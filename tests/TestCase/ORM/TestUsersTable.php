<?php
declare(strict_types=1);

namespace Giginc\Mongodb\Test\TestCase\ORM;

use Giginc\Mongodb\ORM\Table;

/**
 * Minimal concrete Table used by TableTest.
 */
class TestUsersTable extends Table
{
    protected ?string $table = 'test_users';

    protected ?string $alias = 'TestUsers';
}
