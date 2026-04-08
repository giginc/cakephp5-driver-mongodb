<?php
declare(strict_types=1);

namespace Giginc\Cakephp5DriverMongodb\ORM;

use Cake\ORM\Entity;
use Countable;
use IteratorAggregate;
use MongoDB\BSON\ObjectId;
use MongoDB\Collection;
use Traversable;

/**
 * Fluent query builder for a MongoDB collection.
 *
 * Mirrors the slice of CakePHP 5's SelectQuery API that maps cleanly onto
 * MongoDB find options: where(), select(), order(), limit(), offset(). The
 * legacy `find('all', ['conditions' => ...])` array form is intentionally
 * NOT carried over from the v3 plugin — use the fluent methods instead.
 *
 * Terminal methods: all(), first(), count(), toArray().
 */
class Query implements IteratorAggregate, Countable
{
    /** @var array<string, mixed> */
    private array $where = [];

    /** @var array<string, int> */
    private array $select = [];

    /** @var array<string, int> */
    private array $order = [];

    private ?int $limit = null;

    private ?int $offset = null;

    public function __construct(
        private readonly Collection $collection,
        private readonly string $registryAlias,
        private readonly string $entityClass = Entity::class,
    ) {
    }

    /**
     * Append (merge) conditions to the where filter.
     *
     * @param array<string, mixed> $conditions
     */
    public function where(array $conditions): static
    {
        $this->where = array_replace($this->where, $this->normalizeConditions($conditions));

        return $this;
    }

    /**
     * @param array<int, string> $fields
     */
    public function select(array $fields): static
    {
        foreach ($fields as $f) {
            $this->select[$f] = 1;
        }

        return $this;
    }

    /**
     * @param array<string, string|int> $order
     */
    public function order(array $order): static
    {
        foreach ($order as $field => $dir) {
            $this->order[$field] = (is_string($dir) && strtoupper($dir) === 'DESC') || $dir === -1 ? -1 : 1;
        }

        return $this;
    }

    public function limit(int $n): static
    {
        $this->limit = $n;

        return $this;
    }

    public function offset(int $n): static
    {
        $this->offset = $n;

        return $this;
    }

    public function all(): ResultSet
    {
        $cursor = $this->collection->find($this->where, $this->buildOptions());

        return new ResultSet($cursor, $this->registryAlias, $this->entityClass);
    }

    public function first(): ?Entity
    {
        $opts = $this->buildOptions();
        $opts['limit'] = 1;
        $doc = $this->collection->findOne($this->where, $opts);
        if ($doc === null) {
            return null;
        }

        return (new Document($doc, $this->registryAlias))->toEntity($this->entityClass);
    }

    public function count(): int
    {
        return $this->collection->countDocuments($this->where);
    }

    /**
     * @return array<int, Entity>
     */
    public function toArray(): array
    {
        return $this->all()->toArray();
    }

    public function getIterator(): Traversable
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
        if ($this->order !== []) {
            $opts['sort'] = $this->order;
        }
        if ($this->limit !== null) {
            $opts['limit'] = $this->limit;
        }
        if ($this->offset !== null) {
            $opts['skip'] = $this->offset;
        }

        return $opts;
    }

    /**
     * Promote a 24-hex `_id` / `id` string into a real ObjectId so callers can
     * pass either form.
     *
     * @param array<string, mixed> $conditions
     * @return array<string, mixed>
     */
    private function normalizeConditions(array $conditions): array
    {
        foreach (['_id', 'id'] as $key) {
            if (isset($conditions[$key]) && is_string($conditions[$key]) && self::isObjectIdString($conditions[$key])) {
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

    public static function isObjectIdString(string $value): bool
    {
        return (bool)preg_match('/^[a-f0-9]{24}$/i', $value);
    }
}
