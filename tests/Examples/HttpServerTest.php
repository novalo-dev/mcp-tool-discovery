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
    protected static function command(string $address): array
    {
        return [PHP_BINARY, '-S', $address, dirname(__DIR__, 2) . '/docs/examples/http-server.php'];
    }

    // Nothing in memory survives a request here: the index a search built
    // must be on disk for the next request to read instead of building it.
    public function testTheSearchIndexIsKeptOnDiskBetweenRequests(): void
    {
        self::post(['method' => 'tools/call', 'params' => ['name' => 'notes_search_tools', 'arguments' => ['query' => 'note']]], 'reader-token');

        self::assertNotSame([], glob(self::$data . '/index/*.php') ?: []);
    }

    // A cache that cannot write never fails the request, so the server must
    // say why somewhere, or it builds its index on every search unseen.
    public function testAnIndexThatCannotBeKeptIsSaidInTheErrorLog(): void
    {
        $data = sys_get_temp_dir() . '/mcp-http-example-' . bin2hex(random_bytes(6));
        mkdir($data, 0o700, true);
        // A file where the directory of the index should be.
        touch($data . '/index');
        $server = LocalServer::start(static::command(...), ['MCP_NOTES_DATA' => $data]);
        try {
            [$status, $found] = self::post(['method' => 'tools/call', 'params' => ['name' => 'notes_search_tools', 'arguments' => ['action' => 'add', 'object' => 'note']]], 'reader-token', '2025-11-25', $server->url());
            self::assertSame(200, $status);
            self::assertSame('notes_add', $found['result']['structuredContent']['tools'][0]['name'] ?? null);
            self::assertStringContainsString('notes: The index cache cannot create the directory ' . $data . '/index', $server->errors());
        } finally {
            $server->stop();
            LocalServer::remove($data);
        }
    }
}
