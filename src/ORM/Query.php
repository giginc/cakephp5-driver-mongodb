<?php
declare(strict_types=1);

namespace Giginc\Mongodb\ORM;

use Cake\Database\ExpressionInterface;
use Cake\Datasource\ResultSetDecorator;
use Cake\Datasource\ResultSetInterface;
use Cake\ORM\Association;
use Cake\ORM\Entity;
use Cake\ORM\Query\SelectQuery as CakeSelectQuery;
use Cake\ORM\Table as CakeTable;
use Closure;
use MongoDB\BSON\ObjectId;
use MongoDB\Collection;

/**
 * Fluent query builder for a MongoDB collection.
 *
 * Extends Cake\ORM\Query\SelectQuery so that Table::find() can return an
 * instance of this class while satisfying Cake's covariant return type
 * (find(): SelectQuery).
 *
 * The parent constructor IS invoked, which sets up the standard Cake/SQL
 * scaffolding using the *table's default Cake connection*. That scaffolding
 * is never actually used at query time because we override every fluent and
 * terminal method to operate directly on the MongoDB Collection — the parent
 * only exists to satisfy the type system.
 *
 * Supported fluent methods: where(), select(), order()/orderBy(), limit(),
 * offset().
 * Supported terminal methods: all(), first(), count(), toArray(), iteration.
 *
 * Other inherited SQL-oriented methods (join, group, having, union, ...) are
 * NOT supported and will produce undefined behavior if called on a MongoDB
 * query.
 */
class Query extends CakeSelectQuery
{
    private Collection $mongoCollection;

    private string $mongoRegistryAlias;

    /** @var class-string<\Cake\ORM\Entity> */
    private string $mongoEntityClass;

    /** @var array<string, mixed> */
    private array $where = [];

    /** @var array<string, int> */
    private array $select = [];

    /** @var array<string, int> */
    private array $mongoOrder = [];

    private ?int $mongoLimit = null;

    private ?int $mongoOffset = null;

    /**
     * @param \Cake\ORM\Table $table 親の SelectQuery を初期化するために必要 (Cake 既定の DB 接続を使う)
     * @param \MongoDB\Collection $collection 操作対象の MongoDB コレクション
     * @param string $registryAlias Entity が紐付くテーブルエイリアス
     * @param class-string<\Cake\ORM\Entity> $entityClass 生成する Entity クラス
     */
    public function __construct(
        CakeTable $table,
        Collection $collection,
        string $registryAlias,
        string $entityClass = Entity::class,
    ) {
        parent::__construct($table);

        $this->mongoCollection = $collection;
        $this->mongoRegistryAlias = $registryAlias;
        $this->mongoEntityClass = $entityClass;
    }

    /**
     * @param \Cake\Database\ExpressionInterface|\Closure|array|string|null $conditions
     * @param array<string, string> $types
     * @param bool $overwrite
     * @return $this
     */
    public function where(
        ExpressionInterface|Closure|array|string|null $conditions = null,
        array $types = [],
        bool $overwrite = false,
    ) {
        if ($overwrite) {
            $this->where = [];
        }
        if (is_array($conditions)) {
            $this->where = array_replace(
                $this->where,
                $this->normalizeConditions($conditions),
            );
        }

        return $this;
    }

    /**
     * @param \Cake\Database\ExpressionInterface|\Closure|array|string|float|int $fields
     * @param bool $overwrite
     * @return $this
     */
    public function select(
        ExpressionInterface|CakeTable|Association|Closure|array|string|float|int $fields = [],
        bool $overwrite = false,
    ) {
        if ($overwrite) {
            $this->select = [];
        }
        if (is_array($fields)) {
            foreach ($fields as $f) {
                if (is_string($f)) {
                    $this->select[$f] = 1;
                }
            }
        } elseif (is_string($fields)) {
            $this->select[$fields] = 1;
        }

        return $this;
    }

    /**
     * @param \Cake\Database\ExpressionInterface|\Closure|array|string $fields
     * @param bool $overwrite
     * @return $this
     */
    public function orderBy(
        ExpressionInterface|Closure|array|string $fields,
        bool $overwrite = false,
    ) {
        if ($overwrite) {
            $this->mongoOrder = [];
        }
        if (is_array($fields)) {
            foreach ($fields as $field => $dir) {
                if (!is_string($field)) {
                    continue;
                }
                $this->mongoOrder[$field] =
                    (is_string($dir) && strtoupper($dir) === 'DESC') || $dir === -1 ? -1 : 1;
            }
        }

        return $this;
    }

    /**
     * order() は orderBy() の deprecated エイリアス。互換のため orderBy に委譲する。
     *
     * @param \Cake\Database\ExpressionInterface|\Closure|array|string $fields
     * @param bool $overwrite
     * @return $this
     */
    public function order(
        ExpressionInterface|Closure|array|string $fields,
        bool $overwrite = false,
    ) {
        return $this->orderBy($fields, $overwrite);
    }

    /**
     * @param \Cake\Database\ExpressionInterface|int|null $limit
     * @return $this
     */
    public function limit(ExpressionInterface|int|null $limit)
    {
        $this->mongoLimit = is_int($limit) ? $limit : null;

        return $this;
    }

    /**
     * @param \Cake\Database\ExpressionInterface|int|null $offset
     * @return $this
     */
    public function offset(ExpressionInterface|int|null $offset)
    {
        $this->mongoOffset = is_int($offset) ? $offset : null;

        return $this;
    }

    /**
     * カーソルを走査して Entity に hydrate し、Cake\Datasource\ResultSetDecorator
     * (= ResultSetInterface 実装) で包んで返す。
     *
     * @return \Cake\Datasource\ResultSetInterface
     */
    public function all(): ResultSetInterface
    {
        $cursor = $this->mongoCollection->find($this->where, $this->buildOptions());
        $entities = [];
        foreach ($cursor as $doc) {
            $entities[] = (new Document($doc, $this->mongoRegistryAlias))
                ->toEntity($this->mongoEntityClass);
        }

        return new ResultSetDecorator($entities);
    }

    /**
     * @return \Cake\ORM\Entity|null
     */
    public function first(): mixed
    {
        $opts = $this->buildOptions();
        $opts['limit'] = 1;
        $doc = $this->mongoCollection->findOne($this->where, $opts);
        if ($doc === null) {
            return null;
        }

        return (new Document($doc, $this->mongoRegistryAlias))->toEntity($this->mongoEntityClass);
    }

    /**
     * @return int
     */
    public function count(): int
    {
        return $this->mongoCollection->countDocuments($this->where);
    }

    /**
     * @return array<int, \Cake\ORM\Entity>
     */
    public function toArray(): array
    {
        return $this->all()->toArray();
    }

    /**
     * @return \Cake\Datasource\ResultSetInterface
     */
    public function getIterator(): ResultSetInterface
    {
        return $this->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function buildOptions(): array
    {
        $opts = [];
        if ($this->select !== []) {
            $opts['projection'] = $this->select;
        }
        if ($this->mongoOrder !== []) {
            $opts['sort'] = $this->mongoOrder;
        }
        if ($this->mongoLimit !== null) {
            $opts['limit'] = $this->mongoLimit;
        }
        if ($this->mongoOffset !== null) {
            $opts['skip'] = $this->mongoOffset;
        }

        return $opts;
    }

    /**
     * 24-hex の `_id` / `id` 文字列を ObjectId に昇格させる。
     *
     * @param array<string, mixed> $conditions
     * @return array<string, mixed>
     */
    private function normalizeConditions(array $conditions): array
    {
        foreach (['_id', 'id'] as $key) {
            if (
                isset($conditions[$key])
                && is_string($conditions[$key])
                && self::isObjectIdString($conditions[$key])
            ) {
                $conditions['_id'] = new ObjectId($conditions[$key]);
                if ($key === 'id') {
                    unset($conditions['id']);
                }
            } elseif (isset($conditions[$key]) && $conditions[$key] instanceof ObjectId && $key === 'id') {
                $conditions['_id'] = $conditions[$key];
                unset($conditions['id']);
            }
        }

        return $conditions;
    }

    /**
     * @param string $value
     * @return bool
     */
    public static function isObjectIdString(string $value): bool
    {
        return (bool)preg_match('/^[a-f0-9]{24}$/i', $value);
    }
}
