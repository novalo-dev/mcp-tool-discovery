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
 * Because the process stays up, it can also tell a client when its list of
 * tools has changed, which one process per request cannot: initialize opens
 * a session, a GET with that session opens a stream of server messages, and
 * a timer of the event loop sends notifications/tools/list_changed on it when
 * the list the caller may see is no longer the one the client last listed.
 *
 * Install: composer install, in this directory.
 * Run it:  php docs/examples/react/server.php
 * Call it: curl -s http://127.0.0.1:8080/mcp -H 'Authorization: Bearer reader-token' \
 *            -H 'MCP-Protocol-Version: 2025-11-25' \
 *            -d '{"jsonrpc":"2.0","id":1,"method":"tools/list"}'
 *
 * To watch a list change: MCP_NOTES_MODE=direct lists every tool as itself,
 * and MCP_NOTES_GRANTS names a JSON file, read again at each look, that gives
 * a role the rights of another, such as {"reader": "admin"}.
 */

namespace Example\Notes\React;

use Example\Notes\Notes;
use Example\Notes\NotesGate;
use Novalo\McpToolDiscovery\Discovery;
use Novalo\McpToolDiscovery\Mode\Compact\CompactMode;
use Novalo\McpToolDiscovery\Mode\Direct\DirectMode;
use Novalo\McpToolDiscovery\Protocol\Revision;
use Novalo\McpToolDiscovery\Search\Cache\MemoryIndexCache;
use Novalo\McpToolDiscovery\Search\SearchSetup;
use Psr\Http\Message\ServerRequestInterface;
use React\EventLoop\Loop;
use React\Http\HttpServer;
use React\Http\Message\Response;
use React\Socket\SocketServer;
use React\Stream\ThroughStream;

use function Example\Notes\answerHttp;
use function Example\Notes\encode;
use function Example\Notes\originAllowed;
use function Example\Notes\roleOf;
use function Example\Notes\tools;

require __DIR__ . '/vendor/autoload.php';
require dirname(__DIR__) . '/notes.php';
require dirname(__DIR__) . '/http.php';

// Kept for the life of the process and shared by every request. The notes
// stand for the host's database. The index cache is keyed by the tools a
// caller may see, so a reader and an admin each get the index of their own
// tools, built once, and never the other's.
$notes = new Notes();
$search = new SearchSetup(cache: new MemoryIndexCache());
// Compact by default. In compact mode a change of rights moves no line of
// the list, only what the search finds, so there would be nothing to send:
// MCP_NOTES_MODE=direct lists every tool as itself, and the list moves.
$mode = getenv('MCP_NOTES_MODE') === 'direct' ? new DirectMode() : new CompactMode(prefix: 'notes_', batchLimit: 20);
$tools = tools();

/**
 * The Discovery of the caller a request names, with the rights the caller
 * has now: a real server reads them from its database at each request, and
 * the grants file stands for that here.
 *
 * @param callable(string): ?string $header
 */
$discoveryOf = static function (callable $header) use ($notes, $search, $mode): ?Discovery {
    $role = roleOf($header);
    if ($role === null) {
        return null;
    }
    $file = getenv('MCP_NOTES_GRANTS');
    $grants = is_string($file) && is_file($file) ? json_decode((string) file_get_contents($file), true) : null;
    $granted = is_array($grants) ? ($grants[$role] ?? null) : null;

    // Built for this request or this look, never once above them: a
    // Discovery holds the gate of one caller, and the process serves them all.
    return new Discovery($mode, new NotesGate(is_string($granted) ? $granted : $role, $notes), $search);
};

/**
 * The sessions opened by initialize, by their id. Each keeps who opened it,
 * so that no other caller can use it, the revision agreed, the fingerprint
 * of the list the client last received, and the stream it listens on.
 *
 * @var array<string, array{authorization: string, revision: Revision, held: ?string, stream: ?ThroughStream}> $sessions
 */
$sessions = [];

$server = new HttpServer(static function (ServerRequestInterface $request) use ($discoveryOf, $tools, &$sessions): Response {
    $header = static fn(string $name): ?string => $request->hasHeader($name) ? $request->getHeaderLine($name) : null;
    $id = $header('Mcp-Session-Id');
    if ($id !== null && originAllowed($header)) {
        // A session belongs to the caller who opened it: another token, or an
        // id the server never gave, is a session that does not exist.
        if (!isset($sessions[$id]) || $sessions[$id]['authorization'] !== ($header('Authorization') ?? '')) {
            return new Response(404);
        }
        if ($request->getMethod() === 'GET' && $discoveryOf($header) !== null) {
            if (!str_contains($header('Accept') ?? '', 'text/event-stream')) {
                return new Response(406);
            }
            // One stream per session: a client that opens another has let the
            // first one go.
            $sessions[$id]['stream']?->close();
            $stream = new ThroughStream();
            $stream->on('close', static function () use (&$sessions, $id, $stream): void {
                if (isset($sessions[$id]) && $sessions[$id]['stream'] === $stream) {
                    $sessions[$id]['stream'] = null;
                }
            });
            $sessions[$id]['stream'] = $stream;

            return new Response(200, ['Content-Type' => 'text/event-stream', 'Cache-Control' => 'no-cache'], $stream);
        }
    }

    $raw = (string) $request->getBody();
    [$status, $body, $headers] = answerHttp($request->getMethod(), $header, $raw, static fn(): ?Discovery => $discoveryOf($header));
    $message = json_decode($raw, true);
    $method = is_array($message) ? ($message['method'] ?? null) : null;
    $result = is_array($body['result'] ?? null) ? $body['result'] : [];
    $revision = is_string($result['protocolVersion'] ?? null) ? Revision::tryFrom($result['protocolVersion']) : null;

    if ($status === 200 && $method === 'initialize' && $revision !== null && $body !== null) {
        $id = bin2hex(random_bytes(16));
        $sessions[$id] = ['authorization' => $header('Authorization') ?? '', 'revision' => $revision, 'held' => null, 'stream' => null];
        $headers['Mcp-Session-Id'] = $id;
        // Only a server that can tell the client says it will.
        $body['result'] = array_replace($result, ['capabilities' => ['tools' => ['listChanged' => true]]]);
    } elseif ($status === 200 && $method === 'tools/list' && $id !== null && isset($sessions[$id])) {
        // What the client holds now, whichever page it asked for: the
        // fingerprint covers the whole list.
        $discovery = $discoveryOf($header);
        $sessions[$id]['held'] = $discovery?->listFingerprint($tools, $sessions[$id]['revision']);
    }
    if ($body !== null) {
        $headers['Content-Type'] = 'application/json';
    }

    return new Response($status, $headers, $body === null ? '' : encode($body));
});

// The look the process can take between requests. A server that learns of a
// change of rights by an event sends the notification then; this one looks
// at the rights of every listening session on a timer.
Loop::addPeriodicTimer(0.25, static function () use ($discoveryOf, $tools, &$sessions): void {
    foreach ($sessions as $id => $session) {
        if ($session['stream'] === null || $session['held'] === null) {
            continue;
        }
        $authorization = $session['authorization'];
        $discovery = $discoveryOf(static fn(string $name): ?string => strcasecmp($name, 'Authorization') === 0 ? $authorization : null);
        $now = $discovery?->listFingerprint($tools, $session['revision']);
        if ($now !== null && $now !== $session['held']) {
            $session['stream']->write('data: ' . encode(['jsonrpc' => '2.0', 'method' => 'notifications/tools/list_changed']) . "\n\n");
            // Sent once: the client lists again, or it does not, and the
            // next notification is for the next change.
            $sessions[$id]['held'] = $now;
        }
    }
});

$listen = getenv('MCP_LISTEN');
$server->listen(new SocketServer(is_string($listen) && $listen !== '' ? $listen : '127.0.0.1:8080'));
