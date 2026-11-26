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

namespace Novalo\McpToolDiscovery\Tests\Examples;

/**
 * Runs docs/examples/http-server.php behind PHP's built-in web server and
 * calls it over HTTP as a client would, one process per request, as PHP-FPM
 * would run it.
 */
final class HttpServerTest extends HttpServerTestCase
{
    use KeepsIndexOnDisk;

    protected static function command(string $address): array
    {
        return [PHP_BINARY, '-S', $address, dirname(__DIR__, 2) . '/docs/examples/http-server.php'];
    }
}
