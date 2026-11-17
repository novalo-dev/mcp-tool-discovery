<?php

/*
 * This file is part of novalo/mcp-tool-discovery.
 *
 * (c) Novalo <https://novalo.fr>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Example\Notes\Symfony;

use Example\Notes\Notes;
use Novalo\McpToolDiscovery\Search\Cache\FileIndexCache;
use Novalo\McpToolDiscovery\Search\SearchSetup;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Kernel as BaseKernel;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

/**
 * The whole application: one bundle, two services, one route. A real
 * application keeps the same three entries in config/services.yaml and
 * config/routes.yaml.
 */
final class Kernel extends BaseKernel
{
    use MicroKernelTrait;

    public function registerBundles(): iterable
    {
        yield new FrameworkBundle();
    }

    /**
     * The notes stand for the host's database. Their directory is the one
     * the index cache writes to as well, made here so neither has to.
     */
    public static function notes(string $data): Notes
    {
        if (!is_dir($data)) {
            mkdir($data, 0o700, true);
        }

        return new Notes($data . '/notes.json');
    }

    protected function configureContainer(ContainerConfigurator $container): void
    {
        $container->extension('framework', [
            'secret' => 'not-a-secret-the-example-uses',
            'http_method_override' => false,
            'handle_all_throwables' => true,
            'php_errors' => ['log' => true],
            'router' => ['utf8' => true],
        ]);

        // Read when a service is built, not when the container is compiled,
        // so the compiled container in var/cache serves any directory.
        $container->parameters()->set('env(MCP_NOTES_DATA)', '%kernel.project_dir%/var/data');

        $services = $container->services();
        // Shared, as every Symfony service is by default: what they hold
        // is the same for every caller. The index cache is keyed by the
        // tools a caller may see, so one caller never reads another's index.
        $services->set(Notes::class)
            ->factory([self::class, 'notes'])
            ->args(['%env(MCP_NOTES_DATA)%']);
        $services->set(SearchSetup::class)
            ->args(['$cache' => service('mcp.index_cache')]);
        $services->set('mcp.index_cache', FileIndexCache::class)
            ->args(['%env(MCP_NOTES_DATA)%/index']);

        // The Discovery is not a service: it holds the gate of one caller,
        // and a shared service would hand it to the next request a worker
        // serves (FrankenPHP, RoadRunner, Swoole). The controller builds it
        // for each request from the shared parts above.
        $services->set(McpController::class)
            ->args([service(Notes::class), service(SearchSetup::class)])
            ->tag('controller.service_arguments');
    }

    protected function configureRoutes(RoutingConfigurator $routes): void
    {
        $routes->add('mcp', '/mcp')->controller(McpController::class);
    }
}
