<?php
declare(strict_types=1);

namespace Giginc\Cakephp5MongodbDriver\ORM;

use Cake\Datasource\Exception\RecordNotFoundException;
use Cake\Event\EventDispatcherInterface;
use Cake\Event\EventDispatcherTrait;
use Cake\ORM\Entity;
use Giginc\Cakephp5MongodbDriver\Database\Connection;
use Giginc\Cakephp5MongodbDriver\Database\Driver\Mongodb;
use MongoDB\BSON\ObjectId;
use MongoDB\Collection as MongoCollection;
use ReflectionClass;
use RuntimeException;

/**
 * Base Table class for MongoDB-backed models.
 *
 * Intentionally does NOT extend Cake\ORM\Table — that class is tightly coupled
 * to the SQL query/schema stack and would add surface area that cannot be
 * serviced by a NoSQL driver. Instead this provides a focused, fluent
 * MongoDB-aware repository implementing the slice of the Cake table API that
 * matters for this scope:
 *   find / get / findById / save / delete / newEntity / patchEntity / exists
 *   updateAll / deleteAll
 */
class Table implements EventDispatcherInterface
{
    use EventDispatcherTrait;

    protected ?string $table = null;

    protected ?string $alias = null;

    protected string $entityClass = Entity::class;

    protected string $primaryKey = '_id';

    protected ?Connection $connection = null;

    protected ?Marshaller $marshaller = null;

    /**
     * @param array{table?: string, alias?: string, connection?: Connection, entityClass?: class-string<Entity>} $config
     */
    public function __construct(array $config = [])
    {
        if (isset($config['table'])) {
            $this->table = $config['table'];
        }
        if (isset($config['alias'])) {
            $this->alias = $config['alias'];
        }
        if (isset($config['connection'])) {
            $this->connection = $config['connection'];
        }
        if (isset($config['entityClass'])) {
            $this->entityClass = $config['entityClass'];
        }

        $this->initialize($config);
    }

    /**
     * Subclass hook, mirroring Cake\ORM\Table::initialize().
     *
     * @param array<string, mixed> $config
     */
    public function initialize(array $config): void
    {
    }

    public function setConnection(Connection $connection): static
    {
        $this->connection = $connection;

        return $this;
    }

    public function getConnection(): Connection
    {
        if ($this->connection === null) {
            throw new RuntimeException('No MongoDB Connection set on ' . static::class);
        }

        return $this->connection;
    }

    public function setTable(string $table): static
    {
        $this->table = $table;

        return $this;
    }

    public function getTable(): string
    {
        if ($this->table !== null) {
            return $this->table;
        }
        $name = (new ReflectionClass($this))->getShortName();
        $name = preg_replace('/Table$/', '', $name) ?? $name;

        return $this->table = strtolower($name);
    }

    public function setAlias(string $alias): static
    {
        $this->alias = $alias;

        return $this;
    }

    public function getAlias(): string
    {
        return $this->alias ??= (new ReflectionClass($this))->getShortName();
    }

    public function getRegistryAlias(): string
    {
        return $this->getAlias();
    }

    public function setEntityClass(string $name): static
    {
        $this->entityClass = $name;

        return $this;
    }

    public function getEntityClass(): string
    {
        return $this->entityClass;
    }

    public function getPrimaryKey(): string
    {
        return $this->primaryKey;
    }

    /** Schemaless. */
    public function hasField(string $field): bool
    {
        return true;
    }

    protected function getCollection(): MongoCollection
    {
        $driver = $this->getConnection()->getDriver();
        if (!$driver instanceof Mongodb) {
            throw new RuntimeException('Connection driver must be ' . Mongodb::class);
        }

        return $driver->getCollection($this->getTable());
    }

    public function marshaller(): Marshaller
    {
        return $this->marshaller ??= new Marshaller($this->getAlias(), $this->entityClass);
    }

    /**
     * Start a fluent query.
     *
     * @param string $type Reserved for future custom finders. Currently only "all" is supported.
     * @param array<string, mixed> $options
     */
    public function find(string $type = 'all', array $options = []): Query
    {
        $query = new Query($this->getCollection(), $this->getAlias(), $this->entityClass);
        if (!empty($options['conditions']) && is_array($options['conditions'])) {
            $query->where($options['conditions']);
        }
        if (!empty($options['fields']) && is_array($options['fields'])) {
            $query->select($options['fields']);
        }
        if (isset($options['limit'])) {
            $query->limit((int)$options['limit']);
        }
        if (isset($options['order']) && is_array($options['order'])) {
            $query->order($options['order']);
        }

        return $query;
    }

    /**
     * @param array<string, mixed> $options
     */
    public function get(mixed $primaryKey, array $options = []): Entity
    {
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

    public function findById(mixed $id): ?Entity
    {
        return $this->find()->where(['_id' => $id])->first();
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, mixed> $options
     */
    public function newEntity(array $data, array $options = []): Entity
    {
        return $this->marshaller()->one($data);
    }

    /**
     * @param array<int, array<string, mixed>> $data
     * @param array<string, mixed> $options
     * @return array<int, Entity>
     */
    public function newEntities(array $data, array $options = []): array
    {
        return $this->marshaller()->many($data);
    }

    public function newEmptyEntity(): Entity
    {
        return $this->marshaller()->one([]);
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, mixed> $options
     */
    public function patchEntity(Entity $entity, array $data, array $options = []): Entity
    {
        foreach ($data as $k => $v) {
            $entity->set($k, $v);
        }

        return $entity;
    }

    /**
     * @param iterable<int, Entity> $entities
     * @param array<int, array<string, mixed>> $data
     * @param array<string, mixed> $options
     * @return array<int, Entity>
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
     * @param array<string, mixed> $options
     */
    public function save(Entity $entity, array $options = []): Entity|false
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

            return $result instanceof Entity ? $result : false;
        }

        $payload = $this->marshaller()->toBson($entity);
        $collection = $this->getCollection();

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
     * @param array<string, mixed> $options
     */
    public function delete(Entity $entity, array $options = []): bool
    {
        $id = $entity->get('_id') ?? $entity->get('id');
        if ($id === null) {
            return false;
        }
        if (is_string($id) && Query::isObjectIdString($id)) {
            $id = new ObjectId($id);
        }

        $result = $this->getCollection()->deleteOne(['_id' => $id]);

        return $result->isAcknowledged() && $result->getDeletedCount() > 0;
    }

    /**
     * @param array<string, mixed> $conditions
     */
    public function deleteAll(array $conditions): int
    {
        return $this->getCollection()->deleteMany($conditions)->getDeletedCount();
    }

    /**
     * @param array<string, mixed> $fields
     * @param array<string, mixed> $conditions
     */
    public function updateAll(array $fields, array $conditions): int
    {
        return $this->getCollection()->updateMany($conditions, ['$set' => $fields])->getModifiedCount();
    }

    /**
     * @param array<string, mixed> $conditions
     */
    public function exists(array $conditions): bool
    {
        return $this->getCollection()->countDocuments($conditions, ['limit' => 1]) > 0;
    }
}
