<?php
declare(strict_types=1);

namespace Giginc\Cakephp5DriverMongodb;

use Cake\Core\BasePlugin;
use Cake\Core\PluginApplicationInterface;

/**
 * Plugin entry point so the package can be loaded via
 * `bin/cake plugin load Giginc/Cakephp5DriverMongodb` or auto-discovered
 * through the `extra.cakephp.plugin` composer key.
 */
class Plugin extends BasePlugin
{
    protected ?string $name = 'Giginc/Cakephp5DriverMongodb';

    protected bool $bootstrapEnabled = false;

    protected bool $consoleEnabled = false;

    protected bool $middlewareEnabled = false;

    protected bool $routesEnabled = false;

    public function bootstrap(PluginApplicationInterface $app): void
    {
        // No-op: this plugin only contributes a Connection/Driver/Table base.
    }
}
