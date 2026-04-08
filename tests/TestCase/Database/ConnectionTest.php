<?php
declare(strict_types=1);

namespace Giginc\Cakephp5DriverMongodb\Test\TestCase\Database;

use Giginc\Cakephp5DriverMongodb\Database\Connection;
use Giginc\Cakephp5DriverMongodb\Database\Driver\Mongodb;
use PHPUnit\Framework\TestCase;

/**
 * Smoke + integration test for Connection.
 *
 * The integration portion connects to a real MongoDB instance defined by
 * MONGODB_TEST_URI / MONGODB_TEST_DATABASE (see tests/bootstrap.php). When no
 * server is reachable the integration test is skipped so unit runs still pass.
 */
class ConnectionTest extends TestCase
{
    private function makeConnection(): Connection
    {
        return new Connection([
            'name'     => 'test',
            'uri'      => MONGODB_TEST_URI,
            'database' => MONGODB_TEST_DATABASE,
        ]);
    }

    public function testGetDriverReturnsMongodb(): void
    {
        $conn = $this->makeConnection();
        $this->assertInstanceOf(Mongodb::class, $conn->getDriver());
    }

    public function testConfigName(): void
    {
        $conn = $this->makeConnection();
        $this->assertSame('test', $conn->configName());
    }

    public function testConnectSucceedsAgainstLiveServer(): void
    {
        $conn = $this->makeConnection();
        try {
            $connected = $conn->getDriver()->connect();
        } catch (\Throwable $e) {
            $this->markTestSkipped('No MongoDB reachable at ' . MONGODB_TEST_URI . ': ' . $e->getMessage());
        }
        $this->assertTrue($connected);
        $this->assertTrue($conn->getDriver()->isConnected());
    }
}
