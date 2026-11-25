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
 * What a server on logiscape/mcp-sdk-php needs around the library: the two
 * handlers, the instructions sent with the SDK's handshake, the revision the
 * SDK agreed on, the arguments of a call, and the library's answer handed
 * back as a Result. docs/examples/logiscape/server.php wires them over stdio,
 * docs/examples/logiscape/http-server.php over HTTP.
 */

namespace Example\Notes\Logiscape;

use Mcp\Server\InitializationOptions;
use Mcp\Server\Server;
use Mcp\Server\ServerSession;
use Mcp\Server\Transport\Http\HttpMessage;
use Mcp\Server\Transport\Transport;
use Mcp\Shared\ErrorData;
use Mcp\Shared\McpError;
use Mcp\Types\DiscoverResult;
use Mcp\Types\InitializeResult;
use Mcp\Types\RequestWrapperInterface;
use Mcp\Types\Result;
use Novalo\McpToolDiscovery\Discovery;
use Novalo\McpToolDiscovery\Exception\InvalidCursor;
use Novalo\McpToolDiscovery\Exception\UnsupportedRevision;
use Novalo\McpToolDiscovery\Protocol\Revision;
use Novalo\McpToolDiscovery\Tool\ToolSet;

/**
 * tools/list and tools/call, answered by the library. The SDK's Server keys
 * its handlers by method and announces tools because it has a tools/list
 * handler.
 */
function serve(Server $server, Discovery $discovery, ToolSet $tools): void
{
    $server->registerHandler('tools/list', static function (mixed $params) use ($discovery, $tools, $server): Result {
        $cursor = is_object($params) && isset($params->cursor) && is_string($params->cursor) ? $params->cursor : null;
        try {
            return answer($discovery->listPage($tools, revision($server), $cursor, 50));
        } catch (InvalidCursor $error) {
            // The client's mistake, so the invalid params error the specification
            // asks for instead of the internal error the SDK would send.
            throw new McpError(new ErrorData(-32602, $error->getMessage()));
        }
    });

    $server->registerHandler('tools/call', static function (mixed $params) use ($discovery, $tools, $server): Result {
        $name = is_object($params) && isset($params->name) && is_string($params->name) ? $params->name : '';

        return answer($discovery->callTool($name, arguments($params), $tools, revision($server)));
    });
}

/**
 * The SDK's session, with the instructions in initialize and server/discover.
 * The SDK has no setting for them, and they are what tells the model, before
 * any tool, that the list is not the whole server.
 */
final class InstructedSession extends ServerSession
{
    public function __construct(private readonly string $instructions, Transport $transport, InitializationOptions $options)
    {
        parent::__construct($transport, $options);
    }

    protected function handleInitialize(RequestWrapperInterface $request, callable $respond): void
    {
        parent::handleInitialize($request, $this->instructed($respond));
    }

    protected function handleDiscover(callable $respond): void
    {
        parent::handleDiscover($this->instructed($respond));
    }

    private function instructed(callable $respond): callable
    {
        return function (mixed $result) use ($respond): void {
            if ($result instanceof InitializeResult || $result instanceof DiscoverResult) {
                $result->instructions = $this->instructions;
            }
            $respond($result);
        };
    }
}

/**
 * The instructions, set on the answers to initialize and server/discover in
 * the JSON body the SDK's HTTP runner built. Over HTTP the runner makes its
 * session itself, so InstructedSession cannot take its place; the answer is
 * the one place left. The runner answers in JSON unless enable_sse is set,
 * which http-server.php leaves off.
 */
function instruct(HttpMessage $request, HttpMessage $response, string $instructions): void
{
    $asked = json_decode((string) $request->getBody());
    // The ids of the requests that open a session or discover the server,
    // so an answer of another method is left as it is.
    $ids = [];
    foreach (is_array($asked) ? $asked : [$asked] as $message) {
        if ($message instanceof \stdClass && in_array($message->method ?? null, ['initialize', 'server/discover'], true) && isset($message->id)) {
            $ids[] = $message->id;
        }
    }
    // Decoded as objects, so that an empty object of the answer, such as a
    // capability with no option, is encoded again as {} and not [].
    $answered = $ids === [] ? null : json_decode((string) $response->getBody());
    $changed = false;
    foreach (is_array($answered) ? $answered : [$answered] as $message) {
        if ($message instanceof \stdClass && isset($message->id, $message->result) && $message->result instanceof \stdClass && in_array($message->id, $ids, true)) {
            $message->result->instructions = $instructions;
            $changed = true;
        }
    }
    if ($changed) {
        $response->setBody(json_encode($answered, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        $response->removeHeader('Content-Length');
    }
}

/**
 * The SDK has already agreed on the revision, through the handshake or the
 * request's _meta in the stateless revision; the library cuts its answers to
 * the same one.
 */
function revision(Server $server): Revision
{
    $agreed = $server->getSession()?->getNegotiatedProtocolVersion() ?? Revision::latestWithHandshake()->value;
    try {
        return Revision::fromString($agreed);
    } catch (UnsupportedRevision) {
        // An SDK newer than the library may agree on a revision the library
        // does not shape yet: answer in the latest one it shapes that the
        // SDK can also hold a session for.
        return Revision::latestWithHandshake();
    }
}

/**
 * A result the library already shaped for the revision, as the SDK wants it.
 * Its fields go on the Result as they are; the SDK removes resultType for a
 * client of an older revision and keeps it for the stateless one.
 *
 * @param array<string, mixed> $shaped
 */
function answer(array $shaped): Result
{
    return Result::fromResponseData($shaped);
}

/**
 * The SDK reads the arguments of tools/call as an array, objects within it
 * included, which is how the library reads them.
 *
 * @return array<string, mixed>
 */
function arguments(mixed $params): array
{
    $arguments = is_object($params) && isset($params->arguments) && is_array($params->arguments) ? $params->arguments : [];

    /** @var array<string, mixed> a JSON object decoded as an array */
    return $arguments;
}
