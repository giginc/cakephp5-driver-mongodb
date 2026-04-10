<?php
declare(strict_types=1);

namespace Giginc\Mongodb\Test\TestCase\Database\Driver;

use Giginc\Mongodb\Database\Driver\Mongodb;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the Mongodb driver wrapper. These do not require a running
 * MongoDB instance — they exercise configuration handling only.
 */
class MongodbTest extends TestCase
{
    public function testEnabledReflectsExtension(): void
    {
        $driver = new Mongodb(['database' => 'x']);
        $this->assertSame(extension_loaded('mongodb'), $driver->enabled());
    }

    public function testNotConnectedByDefault(): void
    {
        $driver = new Mongodb(['database' => 'x']);
        $this->assertFalse($driver->isConnected());
    }

    public function testConfigDefaults(): void
    {
        $driver = new Mongodb(['database' => 'mydb']);
        $cfg = $driver->config();
        $this->assertSame('mydb', $cfg['database']);
        $this->assertSame(27017, $cfg['port']);
        $this->assertSame('127.0.0.1', $cfg['host']);
    }
}
