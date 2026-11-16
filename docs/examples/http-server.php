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
 * The notes server of stdio-server.php, served over HTTP the way most PHP
 * applications run: one process per request, behind PHP-FPM or any web
 * server. It answers each JSON-RPC message of the Streamable HTTP transport
 * with one JSON body and keeps no session, so every request carries what the
 * server needs: the caller in its Authorization header, the revision in its
 * MCP-Protocol-Version header. The tests run it with PHP's built-in server.
 *
 * Run it:  php -S 127.0.0.1:8080 docs/examples/http-server.php
 * Call it: curl -s http://127.0.0.1:8080/mcp -H 'Authorization: Bearer reader-token' \
 *            -H 'MCP-Protocol-Version: 2025-11-25' \
 *            -d '{"jsonrpc":"2.0","id":1,"method":"tools/list"}'
 *
 * It speaks every revision the library knows: those that open with an
 * `initialize` handshake, and the stateless one of 2026-07-28, where each
 * request names its revision in `_meta` and `server/discover` replaces the
 * handshake. Such a request sends `MCP-Protocol-Version: 2026-07-28` and
 * repeats it in params._meta["io.modelcontextprotocol/protocolVersion"].
 */

namespace Example\Notes;

use Novalo\McpToolDiscovery\Discovery;
use Novalo\McpToolDiscovery\Mode\Compact\CompactMode;
use Novalo\McpToolDiscovery\Protocol\Revision;
use Novalo\McpToolDiscovery\Search\Cache\FileIndexCache;
use Novalo\McpToolDiscovery\Search\SearchSetup;
use Novalo\McpToolDiscovery\Tool\Tool;
use Novalo\McpToolDiscovery\Tool\ToolSet;

// In a project of your own: require 'vendor/autoload.php'.
require dirname(__DIR__, 2) . '/vendor/autoload.php';
require __DIR__ . '/notes.php';

// Who a token names. A real server checks an OAuth access token here; what
// the library needs is only the caller it names, to build the gate.
const TOKENS = ['reader-token' => 'reader', 'admin-token' => 'admin'];

/**
 * Sends the answer and ends the request.
 *
 * @param array<string, mixed>|null $body
 * @param array<string, string>     $headers
 */
function reply(int $status, ?array $body = null, array $headers = []): never
{
    http_response_code($status);
    foreach ($headers as $name => $value) {
        header($name . ': ' . $value);
    }
    if ($body !== null) {
        header('Content-Type: application/json');
        // The answers are already shaped for the revision, empty objects
        // included: encoding them as they are is enough.
        echo json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    exit;
}

/**
 * @param array<string, mixed>|null $data
 *
 * @return array<string, mixed>
 */
function error(mixed $id, int $code, string $message, ?array $data = null): array
{
    $error = ['code' => $code, 'message' => $message] + ($data === null ? [] : ['data' => $data]);

    return ['jsonrpc' => '2.0', 'id' => $id, 'error' => $error];
}

/**
 * The revisions this server speaks, for a client that asked for another.
 *
 * @return list<string>
 */
function supported(): array
{
    return array_map(static fn(Revision $revision): string => $revision->value, Revision::cases());
}

$server = $_SERVER;
$header = static fn(string $name): ?string => is_string($server[$name] ?? null) ? $server[$name] : null;

// The specification asks a server to check Origin, so that a page the person
// opens in a browser cannot reach a server that listens on their machine.
$origin = $header('HTTP_ORIGIN');
if ($origin !== null && !in_array(parse_url($origin, PHP_URL_HOST), ['localhost', '127.0.0.1'], true)) {
    reply(403);
}
// No stream of messages from the server, so nothing to open with GET.
if ($header('REQUEST_METHOD') !== 'POST') {
    reply(405, null, ['Allow' => 'POST']);
}
$token = preg_match('/^Bearer (\S+)$/', $header('HTTP_AUTHORIZATION') ?? '', $match) === 1 ? $match[1] : '';
$role = TOKENS[$token] ?? null;
if ($role === null) {
    reply(401, null, ['WWW-Authenticate' => 'Bearer']);
}

$message = json_decode((string) file_get_contents('php://input'), true);
if (!is_array($message)) {
    reply(400, error(null, -32700, 'Parse error.'));
}
// A notification, or a client's answer to a request: accepted, nothing to say.
if (!array_key_exists('id', $message) || !is_string($message['method'] ?? null)) {
    reply(202);
}
$id = $message['id'];
$method = $message['method'];
$params = is_array($message['params'] ?? null) ? $message['params'] : [];

$version = $header('HTTP_MCP_PROTOCOL_VERSION');
$meta = is_array($params['_meta'] ?? null) ? $params['_meta'] : [];
$stated = $meta['io.modelcontextprotocol/protocolVersion'] ?? null;

if ($stated !== null || $method === 'server/discover') {
    // A stateless request names its revision in _meta, and the header must
    // say the same, or the request is malformed.
    if (!is_string($stated) || $stated !== $version) {
        reply(400, error($id, -32600, 'The protocol version in _meta must match the MCP-Protocol-Version header.'));
    }
    $revision = Revision::tryFrom($stated);
    // Only a stateless revision is named this way; for any other the client
    // gets the list to choose from, in the error its revision defines.
    if ($revision === null || !$revision->isStateless()) {
        reply(400, error($id, -32022, 'Unsupported protocol version.', ['requested' => $stated, 'supported' => supported()]));
    }
    if ($method === 'server/discover') {
        // The same for every caller, so any cache may keep it.
        reply(200, ['jsonrpc' => '2.0', 'id' => $id, 'result' => [
            'supportedVersions' => supported(),
            'capabilities' => ['tools' => new \stdClass()],
            'ttlMs' => 3_600_000,
            'cacheScope' => 'public',
            'resultType' => 'complete',
            '_meta' => ['io.modelcontextprotocol/serverInfo' => ['name' => 'notes', 'version' => '1.0.0']],
        ]]);
    }
} else {
    if ($method === 'initialize') {
        reply(200, ['jsonrpc' => '2.0', 'id' => $id, 'result' => [
            'protocolVersion' => negotiate($params['protocolVersion'] ?? null)->value,
            'capabilities' => ['tools' => new \stdClass()],
            'serverInfo' => ['name' => 'notes', 'version' => '1.0.0'],
        ]]);
    }

    // After initialize the client sends the agreed revision on every request.
    // A request without it comes from a client of 2025-03-26, which had no
    // header, as the specification says to assume. A stateless revision with
    // no _meta is not a request that revision defines.
    $revision = $version === null ? Revision::V2025_03_26 : Revision::tryFrom($version);
    if ($revision === null || $revision->isStateless()) {
        reply(400, error($id, -32600, 'Unsupported MCP-Protocol-Version.'));
    }
}

$data = getenv('MCP_NOTES_DATA');
// A directory only this server writes to: the index cache runs its files.
$data = is_string($data) && $data !== '' ? $data : __DIR__ . '/var';
if (!is_dir($data)) {
    mkdir($data, 0o700, true);
}

$tools = ToolSet::of(array_map(Tool::fromArray(...), definitions()));
// One Discovery per request, with the gate of the caller this request names.
// Every request is a new process here, so the index cache saves building the
// search index again each time a client searches.
$discovery = new Discovery(
    new CompactMode(prefix: 'notes_', batchLimit: 20),
    new NotesGate($role, new Notes($data . '/notes.json')),
    new SearchSetup(cache: new FileIndexCache($data . '/index')),
);

if ($method === 'tools/call') {
    $name = $params['name'] ?? null;
    $arguments = $params['arguments'] ?? [];
    if (!is_string($name) || !is_array($arguments)) {
        reply(200, error($id, -32602, 'tools/call needs a name and an object of arguments.'));
    }

    /** @var array<string, mixed> $arguments a JSON object decoded as an array */
    reply(200, ['jsonrpc' => '2.0', 'id' => $id, 'result' => $discovery->callTool($name, $arguments, $tools, $revision)]);
}

$result = match ($method) {
    // The stateless revision has no ping: a request there is its own check.
    'ping' => $revision->isStateless() ? null : new \stdClass(),
    'tools/list' => $discovery->listTools($tools, $revision),
    default => null,
};
reply(200, $result === null ? error($id, -32601, 'Method not found.') : ['jsonrpc' => '2.0', 'id' => $id, 'result' => $result]);
