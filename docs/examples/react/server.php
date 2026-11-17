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

/*
 * The notes server of http-server.php, in one PHP process that stays up and
 * serves every request, on ReactPHP's event loop. It answers exactly as the
 * PHP-FPM server does, from the same http.php; what changes is what lives
 * between requests. The process outlives each request, so the notes and the
 * search index stay in memory. The caller does not: each request still
 * builds its own gate and its own Discovery.
 *
 * Install: composer install, in this directory.
 * Run it:  php docs/examples/react/server.php
 * Call it: curl -s http://127.0.0.1:8080/mcp -H 'Authorization: Bearer reader-token' \
 *            -H 'MCP-Protocol-Version: 2025-11-25' \
 *            -d '{"jsonrpc":"2.0","id":1,"method":"tools/list"}'
 */

namespace Example\Notes\React;

use Example\Notes\Notes;
use Example\Notes\NotesGate;
use Novalo\McpToolDiscovery\Discovery;
use Novalo\McpToolDiscovery\Mode\Compact\CompactMode;
use Novalo\McpToolDiscovery\Search\Cache\MemoryIndexCache;
use Novalo\McpToolDiscovery\Search\SearchSetup;
use Psr\Http\Message\ServerRequestInterface;
use React\Http\HttpServer;
use React\Http\Message\Response;
use React\Socket\SocketServer;

use function Example\Notes\answerHttp;
use function Example\Notes\encode;

require __DIR__ . '/vendor/autoload.php';
require dirname(__DIR__) . '/notes.php';
require dirname(__DIR__) . '/http.php';

// Kept for the life of the process and shared by every request. The notes
// stand for the host's database. The index cache is keyed by the tools a
// caller may see, so a reader and an admin each get the index of their own
// tools, built once, and never the other's.
$notes = new Notes();
$search = new SearchSetup(cache: new MemoryIndexCache());

$server = new HttpServer(static function (ServerRequestInterface $request) use ($notes, $search): Response {
    [$status, $body, $headers] = answerHttp(
        $request->getMethod(),
        static fn(string $name): ?string => $request->hasHeader($name) ? $request->getHeaderLine($name) : null,
        (string) $request->getBody(),
        // Built inside the request, never above it: a Discovery holds the
        // gate of one caller, and a process that stays up serves them all.
        static fn(string $role): Discovery => new Discovery(
            new CompactMode(prefix: 'notes_', batchLimit: 20),
            new NotesGate($role, $notes),
            $search,
        ),
    );
    if ($body !== null) {
        $headers['Content-Type'] = 'application/json';
    }

    return new Response($status, $headers, $body === null ? '' : encode($body));
});

$listen = getenv('MCP_LISTEN');
$server->listen(new SocketServer(is_string($listen) && $listen !== '' ? $listen : '127.0.0.1:8080'));
