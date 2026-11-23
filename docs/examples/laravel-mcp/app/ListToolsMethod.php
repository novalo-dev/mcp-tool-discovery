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
use Novalo\McpToolDiscovery\Exception\InvalidCursor;

/**
 * tools/list, answered by the library: the client sees the mode's tools,
 * paged as laravel/mcp pages its own lists.
 */
final class ListToolsMethod extends DiscoveryMethod
{
    public function handle(JsonRpcRequest $request, ServerContext $context): JsonRpcResponse
    {
        $request->validate([
            'cursor' => ['nullable', 'string'],
            'per_page' => ['nullable', 'integer:strict', 'min:1'],
        ]);
        [$discovery, $tools] = $this->discovery($request, $context);
        $perPage = $request->get('per_page');
        $pageSize = max(1, $context->perPage(is_int($perPage) ? $perPage : null));

        try {
            $page = $discovery->listPage($tools, self::revision($request, $context), $request->cursor(), $pageSize);
        } catch (InvalidCursor $error) {
            throw new JsonRpcException($error->getMessage(), -32602, $request->id);
        }

        return JsonRpcResponse::result($request->id, $page);
    }
}
