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

namespace Novalo\McpToolDiscovery\Tests\Laravel;

use Illuminate\Auth\AuthManager;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Foundation\Application;
use Illuminate\Http\Request;
use Novalo\McpToolDiscovery\Tests\Examples\HttpServerTestCase;
use Novalo\McpToolDiscovery\Tests\Examples\KeepsIndexOnDisk;

/**
 * Runs docs/examples/laravel, the notes server as a Laravel application,
 * and asks it all the HTTP server of PHP-FPM is asked. It needs Laravel
 * installed in docs/examples/laravel, so it is a test suite of its own, run
 * by its own CI job at the version the example pins.
 */
final class LaravelServerTest extends HttpServerTestCase
{
    use KeepsIndexOnDisk;

    private const EXAMPLE = __DIR__ . '/../../docs/examples/laravel';

    public static function setUpBeforeClass(): void
    {
        if (!is_file(self::EXAMPLE . '/vendor/autoload.php')) {
            self::markTestSkipped('Run composer install in docs/examples/laravel first.');
        }
        require_once self::EXAMPLE . '/vendor/autoload.php';
        parent::setUpBeforeClass();
    }

    protected static function command(string $address): array
    {
        return [PHP_BINARY, '-S', $address, self::EXAMPLE . '/public/app.php'];
    }

    // Octane keeps one application and its container for every request it
    // serves, and forgets the guards between two of them, as done here. The
    // singletons are shared, so what one caller may see must come from the
    // request, never from them, and a request with no token must not find
    // the last caller still signed in.
    public function testOneApplicationServingCallersInTurnGivesEachTheirOwnTools(): void
    {
        $_SERVER['MCP_NOTES_DATA'] = self::$data;
        $app = require self::EXAMPLE . '/bootstrap/app.php';
        self::assertInstanceOf(Application::class, $app);
        $kernel = $app->make(Kernel::class);
        $search = (string) json_encode(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => 'notes_search_tools', 'arguments' => ['action' => 'delete', 'object' => 'note']]]);

        try {
            foreach (['admin-token', 'reader-token', 'admin-token', null, 'reader-token'] as $token) {
                $request = Request::create('/mcp', 'POST', server: ($token === null ? [] : ['HTTP_AUTHORIZATION' => 'Bearer ' . $token]) + [
                    'HTTP_MCP_PROTOCOL_VERSION' => '2025-11-25',
                    'CONTENT_TYPE' => 'application/json',
                ], content: $search);
                $response = $kernel->handle($request);
                $kernel->terminate($request, $response);
                $app->make(AuthManager::class)->forgetGuards();
                if ($token === null) {
                    self::assertSame(401, $response->getStatusCode());

                    continue;
                }

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
            $app->flush();
            unset($_SERVER['MCP_NOTES_DATA']);
        }
    }
}
