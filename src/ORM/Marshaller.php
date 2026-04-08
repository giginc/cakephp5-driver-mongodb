<?php
declare(strict_types=1);

namespace Giginc\Cakephp5DriverMongodb\ORM;

use Cake\ORM\Entity;
use DateTimeInterface;
use MongoDB\BSON\ObjectId;
use MongoDB\BSON\UTCDateTime;

/**
 * Bidirectional converter between PHP arrays/Entities and BSON-friendly arrays.
 *
 *  - {@see Marshaller::one()} hydrates a Cake Entity from an input array.
 *  - {@see Marshaller::toBson()} converts an Entity (or array) into a payload
 *    safe to pass to mongodb/mongodb write methods:
 *      * DateTimeInterface → UTCDateTime
 *      * 24-hex `_id` / `id` string → ObjectId
 *      * Nested arrays are converted recursively
 */
class Marshaller
{
    public function __construct(
        private readonly string $registryAlias,
        private readonly string $entityClass = Entity::class,
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    public function one(array $data): Entity
    {
        /** @var Entity $entity */
        $entity = new $this->entityClass($data, [
            'markNew' => true,
            'source'  => $this->registryAlias,
        ]);

        return $entity;
    }

    /**
     * @param array<int, array<string, mixed>> $data
     * @return array<int, Entity>
     */
    public function many(array $data): array
    {
        return array_map(fn ($row) => $this->one($row), $data);
    }

    /**
     * @return array<string, mixed>
     */
    public function toBson(Entity|array $source): array
    {
        $data = $source instanceof Entity ? $source->toArray() : $source;

        return self::convert($data);
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private static function convert(array $data): array
    {
        foreach ($data as $key => $value) {
            if ($value instanceof DateTimeInterface) {
                $data[$key] = new UTCDateTime((int)((float)$value->format('U.u') * 1000));
            } elseif (is_array($value)) {
                $data[$key] = self::convert($value);
            }
        }

        if (isset($data['_id']) && is_string($data['_id']) && Query::isObjectIdString($data['_id'])) {
            $data['_id'] = new ObjectId($data['_id']);
        }
        if (isset($data['id']) && !isset($data['_id'])) {
            if (is_string($data['id']) && Query::isObjectIdString($data['id'])) {
                $data['_id'] = new ObjectId($data['id']);
                unset($data['id']);
            }
        }

        return $data;
    }
}
