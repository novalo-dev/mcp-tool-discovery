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
 * What a server on logiscape/mcp-sdk-php needs around the library: the
 * session that sends the instructions, the revision the SDK agreed on, the
 * arguments of a call, and the library's answer handed back as a Result.
 * docs/examples/logiscape/server.php wires them.
 */

namespace Example\Notes\Logiscape;

use Mcp\Server\InitializationOptions;
use Mcp\Server\Server;
use Mcp\Server\ServerSession;
use Mcp\Server\Transport\Transport;
use Mcp\Types\DiscoverResult;
use Mcp\Types\InitializeResult;
use Mcp\Types\RequestWrapperInterface;
use Mcp\Types\Result;
use Novalo\McpToolDiscovery\Exception\UnsupportedRevision;
use Novalo\McpToolDiscovery\Protocol\Revision;

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
