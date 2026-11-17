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

namespace Novalo\McpToolDiscovery\Tests\Symfony;

use Example\Notes\Symfony\Kernel;
use Novalo\McpToolDiscovery\Tests\Examples\HttpServerTestCase;
use Symfony\Component\HttpFoundation\Request;

/**
 * Runs docs/examples/symfony, the notes server as a Symfony application,
 * and asks it all the HTTP server of PHP-FPM is asked. It needs Symfony
 * installed in docs/examples/symfony, so it is a test suite of its own, run
 * by its own CI job at the version the example pins.
 */
final class SymfonyServerTest extends HttpServerTestCase
{
    private const EXAMPLE = __DIR__ . '/../../docs/examples/symfony';

    public static function setUpBeforeClass(): void
    {
        if (!is_file(self::EXAMPLE . '/vendor/autoload.php')) {
            self::markTestSkipped('Run composer install in docs/examples/symfony first.');
        }
        require_once self::EXAMPLE . '/vendor/autoload.php';
        parent::setUpBeforeClass();
    }

    protected static function command(string $address): array
    {
        return [PHP_BINARY, '-S', $address, self::EXAMPLE . '/public/app.php'];
    }

    // A worker (FrankenPHP, RoadRunner, Swoole) keeps one kernel and its
    // container for every request it serves. The services are shared, so
    // what one caller may see must come from the request, never from them.
    public function testOneKernelServingCallersInTurnGivesEachTheirOwnTools(): void
    {
        $_SERVER['MCP_NOTES_DATA'] = self::$data;
        $kernel = new Kernel('prod', false);
        $search = (string) json_encode(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => 'notes_search_tools', 'arguments' => ['action' => 'delete', 'object' => 'note']]]);

        try {
            foreach (['admin-token', 'reader-token', 'admin-token', 'reader-token'] as $token) {
                $request = Request::create('/mcp', 'POST', server: [
                    'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
                    'HTTP_MCP_PROTOCOL_VERSION' => '2025-11-25',
                    'CONTENT_TYPE' => 'application/json',
                ], content: $search);
                $response = $kernel->handle($request);
                $kernel->terminate($request, $response);

                $answer = json_decode((string) $response->getContent(), true);
                self::assertIsArray($answer);
                $names = array_column($answer['result']['structuredContent']['tools'] ?? [], 'name');
                if ($token === 'admin-token') {
                    self::assertContains('notes_delete', $names);
                } else {
                    self::assertNotContains('notes_delete', $names);
                }
            }
        } finally {
            $kernel->shutdown();
            unset($_SERVER['MCP_NOTES_DATA']);
        }
    }
}
