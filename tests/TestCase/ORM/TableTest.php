<?php
declare(strict_types=1);

namespace Giginc\Cakephp5DriverMongodb\Test\TestCase\ORM;

use Giginc\Cakephp5DriverMongodb\Database\Connection;
use Giginc\Cakephp5DriverMongodb\ORM\Table;
use PHPUnit\Framework\TestCase;

/**
 * Integration test for the Table base class.
 *
 * Uses a dedicated collection in the test database and cleans up after each
 * test. Skips the whole suite if the local MongoDB is not reachable, so unit
 * runs without Docker still pass.
 */
class TableTest extends TestCase
{
    private Connection $connection;

    private TestUsersTable $table;

    protected function setUp(): void
    {
        $this->connection = new Connection([
            'name'     => 'test',
            'uri'      => MONGODB_TEST_URI,
            'database' => MONGODB_TEST_DATABASE,
        ]);

        try {
            $this->connection->getDriver()->connect();
        } catch (\Throwable $e) {
            $this->markTestSkipped('No MongoDB reachable: ' . $e->getMessage());
        }

        $this->table = new TestUsersTable(['connection' => $this->connection]);
        // Clean slate.
        $this->connection->getDriver()->getCollection('test_users')->drop();
    }

    public function testSaveInsertsAndAssignsId(): void
    {
        $entity = $this->table->newEntity(['name' => 'alice', 'status' => 'active']);
        $saved = $this->table->save($entity);
        $this->assertNotFalse($saved);
        $this->assertNotEmpty($entity->get('id'));
        $this->assertFalse($entity->isNew());
    }

    public function testFindReturnsSavedRows(): void
    {
        $this->table->save($this->table->newEntity(['name' => 'a', 'status' => 'active']));
        $this->table->save($this->table->newEntity(['name' => 'b', 'status' => 'active']));
        $this->table->save($this->table->newEntity(['name' => 'c', 'status' => 'inactive']));

        $active = $this->table->find()->where(['status' => 'active'])->toArray();
        $this->assertCount(2, $active);
    }

    public function testGetByIdRoundTrip(): void
    {
        $entity = $this->table->newEntity(['name' => 'bob']);
        $this->table->save($entity);

        $fetched = $this->table->get($entity->get('_id'));
        $this->assertSame('bob', $fetched->get('name'));
    }

    public function testSaveUpdatesExistingEntity(): void
    {
        $entity = $this->table->newEntity(['name' => 'carol', 'score' => 1]);
        $this->table->save($entity);

        $fetched = $this->table->get($entity->get('_id'));
        $fetched->set('score', 42);
        $this->table->save($fetched);

        $again = $this->table->get($entity->get('_id'));
        $this->assertSame(42, $again->get('score'));
    }

    public function testDeleteRemovesRow(): void
    {
        $entity = $this->table->newEntity(['name' => 'dave']);
        $this->table->save($entity);

        $this->assertTrue($this->table->delete($entity));
        $this->assertFalse($this->table->exists(['_id' => $entity->get('_id')]));
    }
}
