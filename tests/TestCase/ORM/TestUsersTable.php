<?php
declare(strict_types=1);

namespace Giginc\Cakephp5DriverMongodb\Test\TestCase\ORM;

use Giginc\Cakephp5DriverMongodb\ORM\Table;

/**
 * Minimal concrete Table used by TableTest.
 */
class TestUsersTable extends Table
{
    protected ?string $table = 'test_users';

    protected ?string $alias = 'TestUsers';
}
