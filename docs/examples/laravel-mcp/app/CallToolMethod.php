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

namespace Example\Notes\LaravelMcp;

use Laravel\Mcp\Exceptions\JsonRpcException;
use Laravel\Mcp\Server\ServerContext;
use Laravel\Mcp\Transport\JsonRpcRequest;
use Laravel\Mcp\Transport\JsonRpcResponse;

/**
 * tools/call, answered by the library: a runner of the mode, or a tool the
 * mode lists itself, runs the tool through ContextGate.
 */
final class CallToolMethod extends DiscoveryMethod
{
    public function handle(JsonRpcRequest $request, ServerContext $context): JsonRpcResponse
    {
        $name = $request->get('name');
        if (!is_string($name)) {
            throw new JsonRpcException('Missing [name] parameter.', -32602, $request->id);
        }
        $arguments = $request->params['arguments'] ?? [];
        // Through JSON, so an empty object and a list stay apart: the
        // library refuses a list as arguments.
        $arguments = json_decode(json_encode($arguments, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($arguments)) {
            throw new JsonRpcException('Invalid params: The [arguments] member must be an object.', -32602, $request->id);
        }
        [$discovery, $tools] = $this->discovery($request, $context);

        return JsonRpcResponse::result($request->id, $discovery->callTool($name, $arguments, $tools, self::revision($request, $context)));
    }
}
