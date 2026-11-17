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

namespace Novalo\McpToolDiscovery\Tests\React;

use Novalo\McpToolDiscovery\Tests\Examples\HttpServerTestCase;

/**
 * Runs docs/examples/react/server.php, one process that serves every
 * request, and asks it all the HTTP server of PHP-FPM is asked. It needs
 * ReactPHP installed in docs/examples/react, so it is a test suite of its
 * own, run by its own CI job at the version the example pins.
 */
final class ReactServerTest extends HttpServerTestCase
{
    public static function setUpBeforeClass(): void
    {
        if (!is_file(dirname(__DIR__, 2) . '/docs/examples/react/vendor/autoload.php')) {
            self::markTestSkipped('Run composer install in docs/examples/react first.');
        }
        parent::setUpBeforeClass();
    }

    protected static function command(string $address): array
    {
        return [PHP_BINARY, dirname(__DIR__, 2) . '/docs/examples/react/server.php'];
    }

    // The process serves every caller in turn with the same index cache and
    // the same notes: what one caller may see must still never reach the
    // next, whichever order they come in.
    public function testCallersTakingTurnsInOneProcessEachSeeTheirOwnTools(): void
    {
        $search = ['method' => 'tools/call', 'params' => ['name' => 'notes_search_tools', 'arguments' => ['action' => 'delete', 'object' => 'note']]];
        $found = static fn(string $token): array => array_column(self::post($search, $token)[1]['result']['structuredContent']['tools'] ?? [], 'name');

        foreach (['admin-token', 'reader-token', 'admin-token', 'reader-token'] as $token) {
            $names = $found($token);
            if ($token === 'admin-token') {
                self::assertContains('notes_delete', $names);
            } else {
                self::assertNotContains('notes_delete', $names);
            }
        }
    }

    // In memory, a note lives as long as the process, with no file at all.
    public function testANoteIsKeptInMemoryWithNothingWrittenToDisk(): void
    {
        [, $added] = self::post(['method' => 'tools/call', 'params' => ['name' => 'notes_run_write_tool', 'arguments' => ['name' => 'notes_add', 'arguments' => ['text' => 'Rotate the keys.']]]], 'reader-token');
        $id = $added['result']['structuredContent']['id'] ?? null;
        self::assertIsInt($id);

        [, $read] = self::post(['method' => 'tools/call', 'params' => ['name' => 'notes_run_read_tool', 'arguments' => ['name' => 'notes_get', 'arguments' => ['id' => $id]]]], 'reader-token');
        self::assertSame('Rotate the keys.', $read['result']['content'][0]['text'] ?? null);
        self::assertFalse(is_dir(self::$data));
    }
}
