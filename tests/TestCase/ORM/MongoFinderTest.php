<?php
declare(strict_types=1);

namespace Giginc\Mongodb\Test\TestCase\ORM;

use Cake\Datasource\Exception\RecordNotFoundException;
use Cake\ORM\Entity;
use Giginc\Mongodb\Database\Connection;
use Giginc\Mongodb\ORM\Table;
use MongoDB\BSON\ObjectId;
use MongoDB\BSON\Regex;
use PHPUnit\Framework\TestCase;

class MongoTestsTable extends Table
{
    public function initialize(array $config): void
    {
        parent::initialize($config);
        $this->setTable('tests');
    }
}

class MongoTest extends Entity
{
}

class MongoFinderTest extends TestCase
{
    private Connection $connection;

    private MongoTestsTable $table;

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

        $this->table = new MongoTestsTable([
            'connection' => $this->connection,
            'entityClass' => MongoTest::class,
        ]);
        $this->table->deleteAll([]);
    }

    protected function tearDown(): void
    {
        $this->table->deleteAll([]);
    }

    public function testFind(): void
    {
        // 基本的な保存と全件取得
        $data = ['foo' => 'bar', 'baz' => true];
        $entity = $this->table->newEntity($data);
        $this->assertNotFalse($this->table->save($entity));

        $this->assertNotEmpty($this->table->find()->toArray());

        // ネストフィールドの検索 (dot notation)
        $data = ['foo' => ['bar' => 'baz']];
        $entity = $this->table->newEntity($data);
        $this->assertNotFalse($this->table->save($entity));

        $this->assertNotEmpty(
            $this->table->find()->where(['foo.bar' => 'baz'])->toArray()
        );

        // regex 検索 ($regex)
        $this->assertNotEmpty(
            $this->table->find()->where(['foo' => ['$regex' => '^bar$']])->toArray()
        );

        // MongoDB\BSON\Regex オブジェクト
        $this->assertNotEmpty(
            $this->table->find()->where(['foo.bar' => new Regex('^b.*z$', 'i')])->toArray()
        );

        // $or 条件
        $this->assertNotEmpty(
            $this->table->find()
                ->where(['$or' => [['foo' => 'bar'], ['foo' => ['bar' => 'baz']]]])
                ->toArray()
        );

        // $and 条件
        $this->assertNotEmpty(
            $this->table->find()
                ->where(['$and' => [['foo' => 'bar'], ['baz' => true]]])
                ->toArray()
        );

        // 比較演算子 ($gte)
        $data = ['foo' => 125];
        $entity = $this->table->newEntity($data);
        $this->assertNotFalse($this->table->save($entity));

        $this->assertNotEmpty(
            $this->table->find()->where(['foo' => ['$gte' => 100]])->toArray()
        );

        // $in / $nin
        $this->assertNotEmpty(
            $this->table->find()->where(['foo' => ['$in' => ['bar', 'baz']]])->toArray()
        );

        $this->assertNotEmpty(
            $this->table->find()->where(['foo' => ['$nin' => ['xxx', 'yyy']]])->toArray()
        );

        // _id 文字列を ObjectId に自動昇格
        $first = $this->table->find()->first();
        $this->assertNotNull($first);
        $this->assertNotEmpty(
            $this->table->find()->where(['_id' => (string)$first->get('_id')])->toArray()
        );

        // limit + offset でページング
        $data = ['foo' => 125, 'bar' => 150];
        $entity = $this->table->newEntity($data);
        $this->assertNotFalse($this->table->save($entity));

        $results = $this->table->find()
            ->orderBy(['bar' => 'DESC'])
            ->limit(2)
            ->offset(0)
            ->toArray();
        $this->assertCount(2, $results);
        $this->assertSame(150, $results[0]->get('bar'));
    }

    public function testFindFirst(): void
    {
        $data = ['foo' => 'zip', 'baz' => true];
        $entity = $this->table->newEntity($data);
        $this->assertNotFalse($this->table->save($entity));
        $this->assertNotNull($this->table->find()->first());

        $data = ['foo' => 'bar', 'baz' => false];
        $entity = $this->table->newEntity($data);
        $this->assertNotFalse($this->table->save($entity));

        // ASC で最初の1件は 'bar'
        $result = $this->table->find()->orderBy(['foo' => 'ASC'])->first();
        $this->assertNotNull($result);
        $this->assertSame('bar', $result->get('foo'));

        // DESC で最初の1件は 'zip'
        $result = $this->table->find()->orderBy(['foo' => 'DESC'])->first();
        $this->assertNotNull($result);
        $this->assertSame('zip', $result->get('foo'));
    }

    public function testFindCount(): void
    {
        $this->table->save($this->table->newEntity(['status' => 'active']));
        $this->table->save($this->table->newEntity(['status' => 'active']));
        $this->table->save($this->table->newEntity(['status' => 'inactive']));

        $this->assertSame(3, $this->table->find()->count());
        $this->assertSame(2, $this->table->find()->where(['status' => 'active'])->count());
    }

    public function testUpdate(): void
    {
        $entity1 = $this->table->save($this->table->newEntity(['name' => 'foo', 'baz' => true]));
        $this->table->save($this->table->newEntity(['name' => 'bar', 'baz' => false]));
        $this->table->save($this->table->newEntity(['name' => 'baz', 'baz' => false]));

        // 既存エンティティの更新
        $entity1->set('name', 'biz');
        $this->assertNotFalse($this->table->save($entity1));

        $updated = $this->table->find()->where(['baz' => true])->first();
        $this->assertNotNull($updated);
        $this->assertSame('biz', $updated->get('name'));

        // updateAll
        $this->assertSame(2, $this->table->updateAll(['name' => 'foo'], ['baz' => false]));
    }

    public function testGet(): void
    {
        $entity = $this->table->newEntity(['name' => 'alice']);
        $this->table->save($entity);

        // ObjectId インスタンスで取得
        $fetched = $this->table->get($entity->get('_id'));
        $this->assertSame('alice', $fetched->get('name'));

        // 24-hex 文字列でも取得できる
        $fetched2 = $this->table->get((string)$entity->get('_id'));
        $this->assertSame('alice', $fetched2->get('name'));

        // 存在しない ID は例外
        $this->expectException(RecordNotFoundException::class);
        $this->table->get(new ObjectId());
    }

    public function testDelete(): void
    {
        $entity = $this->table->newEntity(['name' => 'bob']);
        $this->table->save($entity);

        $this->assertTrue($this->table->delete($entity));
        $this->assertNull($this->table->find()->where(['_id' => $entity->get('_id')])->first());
    }

    public function testDeleteAll(): void
    {
        $this->table->save($this->table->newEntity(['status' => 'active']));
        $this->table->save($this->table->newEntity(['status' => 'active']));
        $this->table->save($this->table->newEntity(['status' => 'inactive']));

        $this->assertSame(2, $this->table->deleteAll(['status' => 'active']));
        $this->assertSame(1, $this->table->find()->count());
    }
}
