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

namespace Novalo\McpToolDiscovery\Tests\Sdk;

use Novalo\McpToolDiscovery\Tests\Examples\SessionHttpServerTestCase;

/**
 * Runs docs/examples/sdk/http-server.php, the notes server on the official
 * MCP PHP SDK over its Streamable HTTP transport, behind PHP's built-in web
 * server: one process per request, as PHP-FPM would run it. The SDK keeps
 * the session between requests; the library is given the caller of each.
 *
 * What every such server answers is in SessionHttpServerTestCase; what
 * follows is where this SDK differs.
 */
final class SdkHttpServerTest extends SessionHttpServerTestCase
{
    protected static function example(): string
    {
        return 'sdk';
    }

    public function testASessionServesOnlyTheCallerThatOpenedIt(): void
    {
        $session = self::open(self::READER);

        // The admin's token on the reader's session: the SDK refuses it, so
        // the admin's gate never runs on what the reader agreed on.
        [$status] = self::call('notes_run_write_tool', ['name' => 'notes_delete', 'arguments' => ['id' => 1]], self::ADMIN, $session);
        self::assertSame(404, $status);
    }

    public function testTheStatelessRevisionNeedsNoSession(): void
    {
        $headers = ['MCP-Protocol-Version: 2026-07-28', 'Mcp-Method: tools/list'];
        [$status, $listed] = self::post(['method' => 'tools/list', 'params' => ['_meta' => self::STATELESS]], self::READER, null, $headers);

        self::assertSame(200, $status);
        self::assertSame('complete', $listed['result']['resultType'] ?? null);
        self::assertArrayHasKey('ttlMs', $listed['result']);
        self::assertSame('notes_search_tools', $listed['result']['tools'][0]['name'] ?? null);
    }
}
