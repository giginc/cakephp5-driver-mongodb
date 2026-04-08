<?php
declare(strict_types=1);

namespace Giginc\Cakephp5DriverMongodb\ORM;

use Cake\Datasource\ResultSetInterface;
use Cake\ORM\Entity;
use Iterator;
use IteratorIterator;
use Traversable;

/**
 * Iterator that wraps a MongoDB cursor and yields hydrated entities lazily.
 *
 * Implements ResultSetInterface enough that consumers can iterate, count,
 * convert to array, and grab the first row. Hydration of each document is
 * delegated to {@see Document::toEntity()}.
 */
class ResultSet implements ResultSetInterface
{
    private Iterator $iterator;

    private int $position = 0;

    private ?Entity $current = null;

    public function __construct(
        Traversable $cursor,
        private readonly string $registryAlias,
        private readonly string $entityClass = Entity::class,
    ) {
        $this->iterator = $cursor instanceof Iterator
            ? $cursor
            : new IteratorIterator($cursor);
    }

    public function current(): mixed
    {
        return $this->current;
    }

    public function key(): mixed
    {
        return $this->position;
    }

    public function next(): void
    {
        $this->position++;
        $this->iterator->next();
        $this->hydrate();
    }

    public function rewind(): void
    {
        $this->position = 0;
        $this->iterator->rewind();
        $this->hydrate();
    }

    public function valid(): bool
    {
        return $this->iterator->valid();
    }

    private function hydrate(): void
    {
        if (!$this->iterator->valid()) {
            $this->current = null;

            return;
        }
        $doc = $this->iterator->current();
        $this->current = (new Document($doc, $this->registryAlias))->toEntity($this->entityClass);
    }

    /**
     * @return array<int, Entity>
     */
    public function toArray(bool $preserveKeys = true): array
    {
        $out = [];
        foreach ($this as $k => $v) {
            $preserveKeys ? $out[$k] = $v : $out[] = $v;
        }

        return $out;
    }

    /**
     * @return array<int, Entity>
     */
    public function toList(): array
    {
        return $this->toArray(false);
    }

    public function count(): int
    {
        return count($this->toArray());
    }

    public function first(): mixed
    {
        foreach ($this as $row) {
            return $row;
        }

        return null;
    }

    /**
     * @return array<int, Entity>
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    public function serialize(): string
    {
        return serialize($this->toArray());
    }

    public function unserialize($data): void
    {
        // No-op: result sets are not designed to be unserialized.
    }

    /**
     * @return array<int, Entity>
     */
    public function __serialize(): array
    {
        return $this->toArray();
    }

    /**
     * @param array<int, Entity> $data
     */
    public function __unserialize(array $data): void
    {
        // No-op
    }
}
