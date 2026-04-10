<?php
declare(strict_types=1);

namespace Giginc\Mongodb\ORM;

use ArrayObject;
use Cake\Database\Expression\QueryExpression;
use Cake\Database\Schema\TableSchema;
use Cake\Database\Schema\TableSchemaInterface;
use Cake\Datasource\ConnectionInterface;
use Psr\SimpleCache\CacheInterface;
use Cake\Datasource\EntityInterface;
use Cake\Datasource\Exception\RecordNotFoundException;
use Cake\ORM\Entity;
use Cake\ORM\Table as CakeTable;
use Closure;
use Giginc\Mongodb\Database\Connection as MongoConnection;
use Giginc\Mongodb\Database\Driver\Mongodb;
use MongoDB\BSON\ObjectId;
use MongoDB\Collection as MongoCollection;
use RuntimeException;

/**
 * Base Table class for MongoDB-backed models.
 *
 * Extends Cake\ORM\Table so that the standard Cake TableRegistry/TableLocator
 * pipeline can instantiate concrete subclasses (the locator's _create()
 * method enforces a Cake\ORM\Table return type).
 *
 * SQL-only behavior inherited from the parent is bypassed in two ways:
 *   1. getSchema() returns an empty TableSchema so no SQL introspection runs.
 *   2. The Mongo-specific data API is exposed via find() / save() /
 *      delete() / newEntity() / patchEntity() / etc, which override the
 *      parent's signatures with MongoDB semantics.
 *
 * find() is overridden to return the MongoDB-aware Query builder while still
 * satisfying Cake\ORM\Table::find()'s covariant SelectQuery return type.
 */
class Table extends CakeTable
{
    private ?Marshaller $mongoMarshaller = null;

    /**
     * MongoDB 用の接続。Cake の親クラスの $_connection は Cake\Database\Connection を
     * 要求するため、それとは別に保持する。
     *
     * @var \Giginc\Mongodb\Database\Connection|null
     */
    private ?MongoConnection $mongoConnection = null;

    /**
     * 親クラスの setConnection() は Cake\Database\Connection 限定だが、
     * MongoDB 用 Connection を受け入れるためにパラメータ型を緩める
     * (PHP の contravariance により親より広い型を受けるのは合法)。
     *
     * @param \Cake\Datasource\ConnectionInterface $connection
     * @return $this
     */
    public function setConnection(ConnectionInterface $connection)
    {
        if ($connection instanceof MongoConnection) {
            $this->mongoConnection = $connection;

            return $this;
        }

        // Cake\Database\Connection など他種は親に委譲
        parent::setConnection($connection);

        return $this;
    }

    /**
     * MongoDB 用接続を返す。
     *
     * @return \Giginc\Mongodb\Database\Connection
     */
    public function getMongoConnection(): MongoConnection
    {
        if ($this->mongoConnection === null) {
            throw new RuntimeException('No MongoDB Connection set on ' . static::class);
        }

        return $this->mongoConnection;
    }

    /**
     * Default connection name. Subclasses should override to point at the
     * MongoDB datasource registered via ConnectionManager (e.g. 'mongodb').
     *
     * @return string
     */
    public static function defaultConnectionName(): string
    {
        return 'default';
    }

    /**
     * MongoDB は schema-less なので空の TableSchema を返し、Cake 親クラスが
     * SQL 経由でスキーマ introspection を試みるのを防ぐ。
     *
     * @return \Cake\Database\Schema\TableSchemaInterface
     */
    public function getSchema(): TableSchemaInterface
    {
        return new TableSchema($this->getTable());
    }

    /**
     * Mongo は schema-less なので常に true。
     *
     * @param string $field
     * @param bool $deep
     * @return bool
     */
    public function hasField(string $field, bool $deep = true): bool
    {
        return true;
    }

    /**
     * Mongo の主キーは常に _id。
     *
     * @return string
     */
    public function getPrimaryKey(): string
    {
        return '_id';
    }

    /**
     * 接続から取得した MongoDB ドライバ経由で対象コレクションを返す。
     *
     * @return \MongoDB\Collection
     */
    protected function getMongoCollection(): MongoCollection
    {
        $driver = $this->getMongoConnection()->getDriver();
        if (!$driver instanceof Mongodb) {
            throw new RuntimeException(
                'Connection driver must be ' . Mongodb::class . ', got ' . $driver::class,
            );
        }

        return $driver->getCollection($this->getTable());
    }

    /**
     * 生の MongoDB\Collection を返す escape hatch。
     *
     * fluent な save/find では表現しきれない $inc upsert などのアトミック操作を
     * 行いたい場合に、サブクラスからではなく利用側コードからも直接アクセスできる
     * よう public で公開する。
     *
     * @return \MongoDB\Collection
     */
    public function getRawCollection(): MongoCollection
    {
        return $this->getMongoCollection();
    }

    /**
     * 内部用 Mongo Marshaller。
     *
     * @return \Giginc\Mongodb\ORM\Marshaller
     */
    private function mongoMarshaller(): Marshaller
    {
        return $this->mongoMarshaller ??= new Marshaller(
            $this->getAlias(),
            $this->getEntityClass(),
        );
    }

    /**
     * Cake\ORM\Table::find() を override し、MongoDB 用の fluent クエリビルダを返す。
     * 戻り値は Cake\ORM\Query\SelectQuery のサブクラスなので親シグネチャと互換。
     *
     * 利用例:
     *   $table->find()
     *       ->where(['user_id' => 123])
     *       ->limit(10)
     *       ->toArray();
     *
     * 引数 $type / $args は将来のカスタムファインダ用に予約。現状は無視する。
     *
     * @param string $type
     * @param mixed ...$args
     * @return \Giginc\Mongodb\ORM\Query
     */
    public function find(string $type = 'all', mixed ...$args): \Cake\ORM\Query\SelectQuery
    {
        return new Query(
            $this,
            $this->getMongoCollection(),
            $this->getAlias(),
            $this->getEntityClass(),
        );
    }

    /**
     * 主キー (_id) で 1 件取得。なければ RecordNotFoundException。
     *
     * @param mixed $primaryKey
     * @param array|string $finder
     * @param \Psr\SimpleCache\CacheInterface|string|null $cache
     * @param \Closure|string|null $cacheKey
     * @param array<string, mixed> ...$args
     * @return \Cake\Datasource\EntityInterface
     */
    public function get(
        mixed $primaryKey,
        array|string $finder = 'all',
        CacheInterface|string|null $cache = null,
        Closure|string|null $cacheKey = null,
        mixed ...$args,
    ): EntityInterface {
        $entity = $this->findById($primaryKey);
        if ($entity === null) {
            throw new RecordNotFoundException(sprintf(
                'Record not found in collection "%s" with primary key [%s]',
                $this->getTable(),
                is_scalar($primaryKey) ? (string)$primaryKey : gettype($primaryKey),
            ));
        }

        return $entity;
    }

    /**
     * @param mixed $id
     * @return \Cake\ORM\Entity|null
     */
    public function findById(mixed $id): ?Entity
    {
        return $this->find()->where(['_id' => $id])->first();
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, mixed> $options
     * @return \Cake\Datasource\EntityInterface
     */
    public function newEntity(array $data, array $options = []): EntityInterface
    {
        return $this->mongoMarshaller()->one($data);
    }

    /**
     * @param array<int, array<string, mixed>> $data
     * @param array<string, mixed> $options
     * @return array<int, \Cake\Datasource\EntityInterface>
     */
    public function newEntities(array $data, array $options = []): array
    {
        return $this->mongoMarshaller()->many($data);
    }

    /**
     * @return \Cake\Datasource\EntityInterface
     */
    public function newEmptyEntity(): EntityInterface
    {
        return $this->mongoMarshaller()->one([]);
    }

    /**
     * @param \Cake\Datasource\EntityInterface $entity
     * @param array<string, mixed> $data
     * @param array<string, mixed> $options
     * @return \Cake\Datasource\EntityInterface
     */
    public function patchEntity(EntityInterface $entity, array $data, array $options = []): EntityInterface
    {
        foreach ($data as $k => $v) {
            $entity->set($k, $v);
        }

        return $entity;
    }

    /**
     * @param iterable<int, \Cake\Datasource\EntityInterface> $entities
     * @param array<int, array<string, mixed>> $data
     * @param array<string, mixed> $options
     * @return array<int, \Cake\Datasource\EntityInterface>
     */
    public function patchEntities(iterable $entities, array $data, array $options = []): array
    {
        $out = [];
        foreach ($entities as $i => $entity) {
            $out[$i] = isset($data[$i]) ? $this->patchEntity($entity, $data[$i], $options) : $entity;
        }

        return $out;
    }

    /**
     * @param \Cake\Datasource\EntityInterface $entity
     * @param \ArrayObject|array $options
     * @return \Cake\Datasource\EntityInterface|false
     */
    public function save(EntityInterface $entity, ArrayObject|array $options = []): EntityInterface|false
    {
        if ($entity->getErrors()) {
            return false;
        }
        if (!$entity->isNew() && !$entity->isDirty()) {
            return $entity;
        }

        $event = $this->dispatchEvent('Model.beforeSave', compact('entity', 'options'));
        if ($event->isStopped()) {
            $result = $event->getResult();

            return $result instanceof EntityInterface ? $result : false;
        }

        $payload = $this->mongoMarshaller()->toBson($entity);
        $collection = $this->getMongoCollection();

        if ($entity->isNew()) {
            if (!isset($payload['_id'])) {
                $payload['_id'] = new ObjectId();
            }
            $result = $collection->insertOne($payload);
            if (!$result->isAcknowledged()) {
                return false;
            }
            $entity->set('_id', (string)$result->getInsertedId());
            $entity->set('id', (string)$result->getInsertedId());
        } else {
            $id = $payload['_id'] ?? null;
            unset($payload['_id'], $payload['id']);
            if ($id === null) {
                throw new RuntimeException('Cannot update entity without an _id.');
            }
            $result = $collection->updateOne(['_id' => $id], ['$set' => $payload]);
            if (!$result->isAcknowledged()) {
                return false;
            }
        }

        $entity->setSource($this->getRegistryAlias());
        $entity->setNew(false);
        $entity->clean();

        $this->dispatchEvent('Model.afterSave', compact('entity', 'options'));

        return $entity;
    }

    /**
     * @param \Cake\Datasource\EntityInterface $entity
     * @param \ArrayObject|array $options
     * @return bool
     */
    public function delete(EntityInterface $entity, ArrayObject|array $options = []): bool
    {
        $id = $entity->get('_id') ?? $entity->get('id');
        if ($id === null) {
            return false;
        }
        if (is_string($id) && Query::isObjectIdString($id)) {
            $id = new ObjectId($id);
        }

        $result = $this->getMongoCollection()->deleteOne(['_id' => $id]);

        return $result->isAcknowledged() && $result->getDeletedCount() > 0;
    }

    /**
     * @param \Closure|array|string|null $conditions
     * @return int
     */
    public function deleteAll(QueryExpression|Closure|array|string|null $conditions): int
    {
        $filter = is_array($conditions) ? $conditions : [];

        return $this->getMongoCollection()->deleteMany($filter)->getDeletedCount();
    }

    /**
     * @param \Cake\ORM\Query\SelectQuery|\Closure|array|string $fields
     * @param \Closure|array|string|null $conditions
     * @return int
     */
    public function updateAll(QueryExpression|Closure|array|string $fields, QueryExpression|Closure|array|string|null $conditions): int
    {
        if (!is_array($fields) || !is_array($conditions)) {
            throw new RuntimeException(
                'MongoDB updateAll() requires array $fields and array $conditions.',
            );
        }

        return $this->getMongoCollection()
            ->updateMany($conditions, ['$set' => $fields])
            ->getModifiedCount();
    }

    /**
     * @param \Closure|array|string|null $conditions
     * @return bool
     */
    public function exists(QueryExpression|Closure|array|string|null $conditions): bool
    {
        $filter = is_array($conditions) ? $conditions : [];

        return $this->getMongoCollection()->countDocuments($filter, ['limit' => 1]) > 0;
    }
}
