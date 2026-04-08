<?php
declare(strict_types=1);

namespace Giginc\Cakephp5DriverMongodb\ORM;

use Cake\ORM\Entity;
use MongoDB\BSON\ObjectId;
use MongoDB\BSON\Serializable;
use MongoDB\BSON\UTCDateTime;
use MongoDB\Model\BSONArray;
use MongoDB\Model\BSONDocument;

/**
 * Converts a raw BSON document into a normalised array or Cake Entity.
 *
 *  - ObjectId  → string
 *  - UTCDateTime → \DateTimeImmutable
 *  - BSONDocument / BSONArray → array (recursive)
 *
 * The original `_id` is preserved and additionally exposed as `id` for
 * convenient string access from application code.
 */
class Document
{
    public function __construct(
        protected BSONDocument|array $document,
        protected string $registryAlias,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        /** @var array<string, mixed> $arr */
        $arr = self::normalize($this->document);

        return $arr;
    }

    public function toEntity(string $entityClass = Entity::class): Entity
    {
        $data = $this->toArray();
        if (isset($data['_id']) && !isset($data['id'])) {
            $data['id'] = (string)$data['_id'];
        }

        /** @var Entity $entity */
        $entity = new $entityClass($data, [
            'markClean' => true,
            'markNew'   => false,
            'source'    => $this->registryAlias,
        ]);

        return $entity;
    }

    protected static function normalize(mixed $value): mixed
    {
        if ($value instanceof ObjectId) {
            return (string)$value;
        }
        if ($value instanceof UTCDateTime) {
            return $value->toDateTime();
        }
        if ($value instanceof BSONDocument || $value instanceof BSONArray) {
            $value = $value->getArrayCopy();
        } elseif ($value instanceof Serializable) {
            $value = $value->bsonSerialize();
        }

        if (is_array($value)) {
            $out = [];
            foreach ($value as $k => $v) {
                $out[$k] = self::normalize($v);
            }

            return $out;
        }

        return $value;
    }
}
