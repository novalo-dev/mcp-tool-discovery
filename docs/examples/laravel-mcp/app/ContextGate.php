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

use Illuminate\Container\Container;
use Laravel\Mcp\Server\ServerContext;
use Laravel\Mcp\Server\Tool as LaravelTool;
use Laravel\Mcp\Server\ToolInvoker;
use Laravel\Mcp\Transport\JsonRpcRequest;
use Laravel\Mcp\Transport\JsonRpcResponse;
use Novalo\McpToolDiscovery\Gate\CallResult;
use Novalo\McpToolDiscovery\Gate\ToolGate;
use Novalo\McpToolDiscovery\Tool\Tool;
use Novalo\McpToolDiscovery\Tool\ToolSet;

/**
 * The library's gate over the tools of one laravel/mcp request. Who sees a
 * tool stays laravel/mcp's decision: only what ServerContext::tools() keeps
 * for this request (shouldRegister() included) is here at all. A call runs
 * the tool as laravel/mcp's own tools/call would, through its ToolInvoker,
 * so validation, container injection and error responses are unchanged.
 */
final class ContextGate implements ToolGate
{
    /**
     * @param array<string, LaravelTool> $tools the tools of this request, by name
     */
    private function __construct(private readonly array $tools, private readonly JsonRpcRequest $request) {}

    /**
     * The tools of this request as the library reads them, and the gate
     * that runs them.
     *
     * @return array{ToolSet, self}
     */
    public static function of(ServerContext $context, JsonRpcRequest $request): array
    {
        $tools = [];
        $definitions = [];
        foreach ($context->tools() as $tool) {
            $tools[$tool->name()] = $tool;
            // Through JSON: toArray() holds objects (the empty properties of
            // a schema), and the library reads plain arrays.
            $definition = json_decode(json_encode($tool->toArray(), JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);
            if (is_array($definition)) {
                /** @var array<string, mixed> $definition */
                $definitions[] = Tool::fromArray($definition);
            }
        }

        return [ToolSet::of($definitions), new self($tools, $request)];
    }

    public function visible(Tool $tool): bool
    {
        return isset($this->tools[$tool->name]);
    }

    public function authorize(Tool $tool, array $arguments): ?CallResult
    {
        // The tools validate their own arguments in handle(), the
        // laravel/mcp way, and answer an error result when they fail.
        return null;
    }

    public function call(Tool $tool, array $arguments): CallResult
    {
        // An array, as laravel/mcp decodes every message: its own check of
        // the arguments takes an empty array for an empty object.
        $params = ['name' => $tool->name, 'arguments' => $arguments];
        $meta = $this->request->meta();
        if ($meta !== null) {
            // The progress token names the runner's call, not this one: the
            // tool's progress cannot reach the client through a runner.
            unset($meta['progressToken']);
            $params['_meta'] = $meta;
        }
        $inner = new JsonRpcRequest($this->request->id, 'tools/call', $params);

        // The tool's Request is resolved from mcp.request, which laravel/mcp
        // bound to the runner's call: bind the tool's own for the time of
        // the call, as its ToolSearch does, then put the runner's back.
        $container = Container::getInstance();
        $outer = $container->bound('mcp.request') ? $container->make('mcp.request') : null;
        $container->instance('mcp.request', $inner->toRequest());

        try {
            $answers = (new ToolInvoker())->invoke($this->tools[$tool->name], $inner);
            $result = null;
            foreach ($answers instanceof JsonRpcResponse ? [$answers] : $answers as $answer) {
                // Notifications streamed by the tool are dropped: they would
                // name a request the client did not send.
                if ($answer instanceof JsonRpcResponse) {
                    $result = $answer->toArray()['result'] ?? $result;
                }
            }
        } finally {
            if ($outer !== null) {
                $container->instance('mcp.request', $outer);
            } else {
                $container->forgetInstance('mcp.request');
            }
        }

        if (!is_array($result)) {
            return CallResult::error(sprintf('%s returned no result.', $tool->name));
        }
        /** @var array<string, mixed> $result */
        $result = json_decode(json_encode($result, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);

        return CallResult::fromArray($result);
    }
}
