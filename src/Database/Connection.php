<?php
declare(strict_types=1);

namespace Giginc\Cakephp5DriverMongodb\Database;

use Cake\Datasource\ConnectionInterface;
use Giginc\Cakephp5DriverMongodb\Database\Driver\Mongodb;
use Psr\Log\LoggerInterface;
use Psr\SimpleCache\CacheInterface;
use RuntimeException;

/**
 * MongoDB connection.
 *
 * Implements ConnectionInterface directly rather than extending the SQL
 * Cake\Database\Connection — pulling in the SQL stack would only add dead
 * weight for a NoSQL driver. Owns one {@see Mongodb} driver instance.
 */
class Connection implements ConnectionInterface
{
    /**
     * @var array<string, mixed>
     */
    protected array $config;

    protected Mongodb $driver;

    protected ?CacheInterface $cacher = null;

    protected ?LoggerInterface $logger = null;

    /**
     * @param array<string, mixed> $config
     */
    public function __construct(array $config)
    {
        $this->config = $config + ['name' => 'default'];

        $driverClass = $config['driver'] ?? Mongodb::class;
        if (!is_string($driverClass) || !class_exists($driverClass)) {
            throw new RuntimeException('Invalid driver class for MongoDB Connection.');
        }
        /** @var Mongodb $driver */
        $driver = new $driverClass($config);
        $this->driver = $driver;
    }

    public function __destruct()
    {
        if ($this->driver->isConnected()) {
            $this->driver->disconnect();
        }
    }

    public function configName(): string
    {
        return (string)($this->config['name'] ?? 'default');
    }

    /**
     * @return array<string, mixed>
     */
    public function config(): array
    {
        return $this->config;
    }

    public function getDriver(string $role = self::ROLE_WRITE): Mongodb
    {
        return $this->driver;
    }

    public function getCacher(): CacheInterface
    {
        if ($this->cacher === null) {
            throw new RuntimeException('No cacher set on MongoDB Connection.');
        }

        return $this->cacher;
    }

    public function setCacher(CacheInterface $cacher)
    {
        $this->cacher = $cacher;

        return $this;
    }

    /**
     * MongoDB multi-document transactions are not supported in this scope:
     * the callback is invoked, but no real transaction is opened.
     */
    public function transactional(callable $callback): mixed
    {
        return $callback($this);
    }

    /**
     * MongoDB has no foreign-key constraints to disable.
     */
    public function disableConstraints(callable $callback): mixed
    {
        return $callback($this);
    }
}
