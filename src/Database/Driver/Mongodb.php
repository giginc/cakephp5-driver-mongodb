<?php
declare(strict_types=1);

namespace Giginc\Cakephp5DriverMongodb\Database\Driver;

use MongoDB\Client;
use MongoDB\Collection;
use MongoDB\Database;
use RuntimeException;

/**
 * MongoDB driver wrapper.
 *
 * Holds a single MongoDB\Client instance and exposes the configured database
 * and its collections. Constructed by {@see \Giginc\Cakephp5DriverMongodb\Database\Connection},
 * not directly by user code.
 *
 * Note: SSH tunnelling (present in the legacy giginc/mongodb plugin) is
 * intentionally out of scope for the initial release.
 */
class Mongodb
{
    /**
     * @var array<string, mixed>
     */
    protected array $config;

    protected ?Client $client = null;

    protected ?Database $database = null;

    protected bool $connected = false;

    /**
     * @param array<string, mixed> $config
     */
    public function __construct(array $config = [])
    {
        $this->config = $config + [
            'host'          => '127.0.0.1',
            'port'          => 27017,
            'database'      => '',
            'username'      => '',
            'password'      => '',
            'authSource'    => null,
            'replicaSet'    => null,
            'uri'           => null,
            'uriOptions'    => [],
            'driverOptions' => [],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function config(): array
    {
        return $this->config;
    }

    public function connect(): bool
    {
        if ($this->connected) {
            return true;
        }

        if (empty($this->config['database'])) {
            throw new RuntimeException('MongoDB driver requires a "database" config value.');
        }

        $this->client = new Client(
            $this->buildUri(),
            (array)$this->config['uriOptions'],
            (array)$this->config['driverOptions'],
        );
        $this->database = $this->client->selectDatabase((string)$this->config['database']);
        $this->connected = true;

        return true;
    }

    public function disconnect(): bool
    {
        $this->client = null;
        $this->database = null;
        $this->connected = false;

        return true;
    }

    public function isConnected(): bool
    {
        return $this->connected;
    }

    public function enabled(): bool
    {
        return extension_loaded('mongodb');
    }

    public function getClient(): Client
    {
        if (!$this->connected) {
            $this->connect();
        }

        /** @var Client $client */
        $client = $this->client;

        return $client;
    }

    public function getDatabase(): Database
    {
        if (!$this->connected) {
            $this->connect();
        }

        /** @var Database $db */
        $db = $this->database;

        return $db;
    }

    public function getCollection(string $name): Collection
    {
        return $this->getDatabase()->selectCollection($name);
    }

    /**
     * Build a mongodb:// URI from configuration.
     *
     * Resolution order:
     *  1. Explicit `uri` config wins, if set.
     *  2. Otherwise compose from host/port/username/password.
     */
    protected function buildUri(): string
    {
        if (!empty($this->config['uri'])) {
            return (string)$this->config['uri'];
        }

        $auth = '';
        if (!empty($this->config['username'])) {
            $auth = rawurlencode((string)$this->config['username']);
            if ((string)$this->config['password'] !== '') {
                $auth .= ':' . rawurlencode((string)$this->config['password']);
            }
            $auth .= '@';
        }

        $host = (string)$this->config['host'];
        if (!str_contains($host, ',') && !empty($this->config['port'])) {
            $host .= ':' . $this->config['port'];
        }

        $query = [];
        if (!empty($this->config['authSource'])) {
            $query['authSource'] = (string)$this->config['authSource'];
        }
        if (!empty($this->config['replicaSet'])) {
            $query['replicaSet'] = (string)$this->config['replicaSet'];
        }

        $qs = $query === [] ? '' : '?' . http_build_query($query);

        return sprintf('mongodb://%s%s/%s%s', $auth, $host, $this->config['database'], $qs);
    }
}
