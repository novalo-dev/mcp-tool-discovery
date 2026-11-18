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

namespace Example\Notes\Laravel;

use Example\Notes\NotesTools;
use Illuminate\Contracts\Auth\Access\Gate;
use Novalo\McpToolDiscovery\Gate\CallResult;
use Novalo\McpToolDiscovery\Gate\ToolGate;
use Novalo\McpToolDiscovery\Tool\Tool;

/**
 * The library's gate, answered by Laravel's: who sees a tool is an ability
 * of the application, asked for the caller of this request. What a call may
 * carry and what it does stay with the tools.
 */
final class AbilityGate implements ToolGate
{
    /**
     * @param Gate $gate Laravel's gate, already for the caller of this request
     */
    public function __construct(private readonly Gate $gate, private readonly NotesTools $tools) {}

    public function visible(Tool $tool): bool
    {
        return $this->gate->allows(McpServiceProvider::SEE, [$tool]);
    }

    public function authorize(Tool $tool, array $arguments): ?CallResult
    {
        return $this->tools->authorize($tool, $arguments);
    }

    public function call(Tool $tool, array $arguments): CallResult
    {
        return $this->tools->call($tool, $arguments);
    }
}
