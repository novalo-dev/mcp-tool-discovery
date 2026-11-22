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
 * The Streamable HTTP side of the notes server, with no server around it:
 * one request in, one answer out. http-server.php runs it once per process,
 * behind PHP-FPM; symfony/src/McpController.php runs it inside a Symfony
 * application; react/server.php runs it for every request of a process
 * that stays up. Kept here once, so the two servers cannot answer a client
 * differently: only what lives between requests changes.
 *
 * It answers each JSON-RPC message with one JSON body and keeps no session,
 * so every request carries what the server needs: the caller in its
 * Authorization header, the revision in its MCP-Protocol-Version header.
 *
 * It speaks every revision the library knows: those that open with an
 * `initialize` handshake, and the stateless one of 2026-07-28, where each
 * request names its revision in `_meta` and `server/discover` replaces the
 * handshake. Such a request sends `MCP-Protocol-Version: 2026-07-28` and
 * repeats it in params._meta["io.modelcontextprotocol/protocolVersion"].
 */

namespace Example\Notes;

use Novalo\McpToolDiscovery\Discovery;
use Novalo\McpToolDiscovery\Protocol\Revision;
use Novalo\McpToolDiscovery\Tool\Tool;
use Novalo\McpToolDiscovery\Tool\ToolSet;

// Who a token names. A real server checks an OAuth access token here; what
// the library needs is only the caller it names, to build the gate.
const TOKENS = ['reader-token' => 'reader', 'admin-token' => 'admin'];

/**
 * The role the request's bearer token names, null when it names none.
 *
 * Kept apart from answerHttp(), so a host whose framework already knows the
 * caller (a Symfony firewall, a middleware) builds the Discovery from that
 * caller and never reads the header twice.
 *
 * @param callable(string): ?string $header a header of the request by its name, null when it has none
 */
function roleOf(callable $header): ?string
{
    $token = preg_match('/^Bearer (\S+)$/', $header('Authorization') ?? '', $match) === 1 ? $match[1] : '';

    return TOKENS[$token] ?? null;
}

/**
 * The answer to one HTTP request: its status, its JSON body if it has one,
 * and its headers.
 *
 * @param callable(string): ?string $header    a header of the request by its name, null when it has none
 * @param \Closure(): ?Discovery     $discovery the Discovery of the caller this request names, built for this
 *                                             request alone; null when it names none, which is answered 401
 *
 * @return array{int, array<string, mixed>|null, array<string, string>}
 */
function answerHttp(string $method, callable $header, string $body, \Closure $discovery): array
{
    if (!originAllowed($header)) {
        return [403, null, []];
    }
    // No stream of messages from the server, so nothing to open with GET.
    if ($method !== 'POST') {
        return [405, null, ['Allow' => 'POST']];
    }
    // One Discovery per request, with the gate of the caller this request
    // names, whether the process ends with the request or serves the next.
    // Asked for before the body is read, so a caller who names no one learns
    // nothing about what the server would make of it.
    $discovery = $discovery();
    if ($discovery === null) {
        return [401, null, ['WWW-Authenticate' => 'Bearer']];
    }

    $message = json_decode($body, true);
    if (!is_array($message)) {
        return [400, error(null, -32700, 'Parse error.'), []];
    }
    // A notification, or a client's answer to a request: accepted, nothing to say.
    if (!array_key_exists('id', $message) || !is_string($message['method'] ?? null)) {
        return [202, null, []];
    }
    $id = $message['id'];
    $method = $message['method'];
    $params = is_array($message['params'] ?? null) ? $message['params'] : [];

    $version = $header('MCP-Protocol-Version');
    $meta = is_array($params['_meta'] ?? null) ? $params['_meta'] : [];
    $stated = $meta['io.modelcontextprotocol/protocolVersion'] ?? null;

    if ($stated !== null || $method === 'server/discover') {
        // A stateless request names its revision in _meta, and the header
        // must say the same, or the request is malformed.
        if (!is_string($stated) || $stated !== $version) {
            return [400, error($id, -32600, 'The protocol version in _meta must match the MCP-Protocol-Version header.'), []];
        }
        $revision = Revision::tryFrom($stated);
        // Only a stateless revision is named this way; for any other the
        // client gets the list to choose from, in the error its revision
        // defines.
        if ($revision === null || !$revision->isStateless()) {
            return [400, error($id, -32022, 'Unsupported protocol version.', ['requested' => $stated, 'supported' => supported()]), []];
        }
        if ($method === 'server/discover') {
            // The same for every caller, so any cache may keep it.
            return [200, ['jsonrpc' => '2.0', 'id' => $id, 'result' => [
                'supportedVersions' => supported(),
                'capabilities' => ['tools' => new \stdClass()],
                'ttlMs' => 3_600_000,
                'cacheScope' => 'public',
                'resultType' => 'complete',
                '_meta' => ['io.modelcontextprotocol/serverInfo' => ['name' => 'notes', 'version' => '1.0.0']],
            ]], []];
        }
    } else {
        if ($method === 'initialize') {
            return [200, ['jsonrpc' => '2.0', 'id' => $id, 'result' => [
                'protocolVersion' => Revision::negotiate($params['protocolVersion'] ?? null)->value,
                'capabilities' => ['tools' => new \stdClass()],
                'serverInfo' => ['name' => 'notes', 'version' => '1.0.0'],
            ]], []];
        }

        // After initialize the client sends the agreed revision on every
        // request. A request without it comes from a client of 2025-03-26,
        // which had no header, as the specification says to assume. A
        // stateless revision with no _meta is not a request that revision
        // defines.
        $revision = $version === null ? Revision::V2025_03_26 : Revision::tryFrom($version);
        if ($revision === null || $revision->isStateless()) {
            return [400, error($id, -32600, 'Unsupported MCP-Protocol-Version.'), []];
        }
    }

    $tools = tools();

    if ($method === 'tools/call') {
        $name = $params['name'] ?? null;
        $arguments = $params['arguments'] ?? [];
        if (!is_string($name) || !is_array($arguments)) {
            return [200, error($id, -32602, 'tools/call needs a name and an object of arguments.'), []];
        }

        /** @var array<string, mixed> $arguments a JSON object decoded as an array */
        return [200, ['jsonrpc' => '2.0', 'id' => $id, 'result' => $discovery->callTool($name, $arguments, $tools, $revision)], []];
    }

    $result = match ($method) {
        // The stateless revision has no ping: a request there is its own check.
        'ping' => $revision->isStateless() ? null : new \stdClass(),
        'tools/list' => $discovery->listTools($tools, $revision),
        default => null,
    };

    return [200, $result === null ? error($id, -32601, 'Method not found.') : ['jsonrpc' => '2.0', 'id' => $id, 'result' => $result], []];
}

/**
 * Whether the request may come from where it says it comes from.
 *
 * The specification asks a server to check Origin, so that a page the person
 * opens in a browser cannot reach a server that listens on their machine.
 * Kept apart so that a server answering more than answerHttp() does, such as
 * the stream of react/server.php, checks it the same way.
 *
 * @param callable(string): ?string $header a header of the request by its name, null when it has none
 */
function originAllowed(callable $header): bool
{
    $origin = $header('Origin');

    return $origin === null || in_array(parse_url($origin, PHP_URL_HOST), ['localhost', '127.0.0.1'], true);
}

/**
 * The tools of the notes server, as the library takes them.
 */
function tools(): ToolSet
{
    return ToolSet::of(array_map(Tool::fromArray(...), definitions()));
}

/**
 * The body of an answer. The answers are already shaped for the revision,
 * empty objects included: encoding them as they are is enough.
 *
 * @param array<string, mixed> $body
 */
function encode(array $body): string
{
    return json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
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
