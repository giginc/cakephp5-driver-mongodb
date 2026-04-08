# cakephp5-driver-mongodb

MongoDB datasource driver for **CakePHP 5** — the modern successor to
[giginc/mongodb](https://github.com/giginc/mongodb-cakephp3).

Targets PHP 8.1+, CakePHP 5, and `mongodb/mongodb` 2.x. The public API follows
CakePHP 5 conventions (fluent query builder, marshaller, entities) and does
**not** preserve the array-based call style of the v3 plugin.

> **Status:** scaffold / work in progress. Connection + Driver + Table base +
> Marshaller/Entity conversion are in scope. Associations, behaviors, and SSH
> tunnelling are deferred to a later release.

## Requirements

- PHP `^8.1`
- `ext-mongodb`
- `mongodb/mongodb` `^2.0`
- `cakephp/cakephp` `^5.0`

## Installation

```bash
composer require giginc/cakephp5-driver-mongodb
```

Then load the plugin (optional — the package only contributes a Connection/
Driver/Table base, so loading is not strictly required):

```bash
bin/cake plugin load Giginc/Cakephp5DriverMongodb
```

## Configuration

In `config/app.php`:

```php
'Datasources' => [
    'mongo' => [
        'className' => \Giginc\Cakephp5DriverMongodb\Database\Connection::class,
        'driver'    => \Giginc\Cakephp5DriverMongodb\Database\Driver\Mongodb::class,
        'host'      => 'localhost',
        'port'      => 27017,
        'database'  => 'my_database',
        'username'  => '',
        'password'  => '',
        // or a full DSN that wins over host/port/auth:
        // 'uri'    => 'mongodb://user:pass@host:27017/my_database?authSource=admin',
    ],
],
```

## Usage

```php
// src/Model/Table/UsersTable.php
namespace App\Model\Table;

use Giginc\Cakephp5DriverMongodb\ORM\Table;

class UsersTable extends Table
{
    protected ?string $table = 'users';
}
```

```php
$users = new UsersTable(['connection' => ConnectionManager::get('mongo')]);

// Fluent queries
$list = $users->find()
    ->where(['status' => 'active'])
    ->order(['created' => 'DESC'])
    ->limit(10)
    ->toArray();

// Single row by _id
$user = $users->get($id);

// Create / update
$entity = $users->newEntity(['name' => 'alice', 'status' => 'active']);
$users->save($entity);

// Delete
$users->delete($entity);
```

## Migration guide (from `giginc/mongodb`)

### Namespace map

| Old (`giginc/mongodb`)                                   | New                                                            |
|----------------------------------------------------------|----------------------------------------------------------------|
| `Giginc\Mongodb\Database\Connection`                     | `Giginc\Cakephp5DriverMongodb\Database\Connection`             |
| `Giginc\Mongodb\Database\Driver\Mongodb`                 | `Giginc\Cakephp5DriverMongodb\Database\Driver\Mongodb`         |
| `Giginc\Mongodb\ORM\Table`                               | `Giginc\Cakephp5DriverMongodb\ORM\Table`                       |
| `Giginc\Mongodb\ORM\Document`                            | `Giginc\Cakephp5DriverMongodb\ORM\Document`                    |
| `Giginc\Mongodb\ORM\ResultSet`                           | `Giginc\Cakephp5DriverMongodb\ORM\ResultSet`                   |
| `Giginc\Mongodb\ORM\MongoFinder` / `MongoQuery`          | `Giginc\Cakephp5DriverMongodb\ORM\Query` (fluent, new design)  |

### API changes (non-exhaustive)

- `find('all', ['conditions' => [...], 'fields' => [...], 'limit' => 10])` →
  `find()->where([...])->select([...])->limit(10)->toArray()`
  The legacy array form is intentionally not supported.
- `get($id)` now throws `Cake\Datasource\Exception\RecordNotFoundException`
  (was: `InvalidPrimaryKeyException`).
- The base `Table` no longer extends `Cake\ORM\Table`. SQL-specific helpers
  (associations, behaviors, SQL query objects) are unavailable in this scope.
- `lastInsertId()`, `transactional()`, `disableConstraints()` are no-ops —
  MongoDB semantics differ and this driver does not fake them.
- SSH tunnelling (`ssh_host`, `ssh_user`, etc. in legacy config) is **removed**
  in this release. Use your own tunnel if needed.

## Development

```bash
# start a local MongoDB for tests
docker compose -f docker-compose.test.yml up -d

composer install
composer test
composer cs-check
composer stan
```

Integration tests read `MONGODB_URI` / `MONGODB_DATABASE` from env, defaulting
to `mongodb://127.0.0.1:27017` and `cakephp5_mongodb_driver_test`.

## License

MIT — see [LICENSE](LICENSE).
