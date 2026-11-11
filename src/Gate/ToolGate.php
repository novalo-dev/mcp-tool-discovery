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

namespace Novalo\McpToolDiscovery\Gate;

use Novalo\McpToolDiscovery\Tool\Tool;

/**
 * The host's controls, the one thing the library never decides.
 *
 * The library knows nothing of users, tokens, scopes or permissions. The host
 * implements this interface for the caller of the current request, and every
 * mode reaches the host's tools only through it: a tool `visible()` refuses is
 * never listed, found by a search or run, and nothing runs without
 * `authorize()` first. Keep each method free of side effects except `call()`.
 */
interface ToolGate
{
    /**
     * Whether the caller may know this tool exists.
     *
     * Answer false for a tool the caller could never run - switched off, out
     * of the token's scopes, beyond the account's rights. An assistant that
     * sees a tool tries it; one it never saw costs nothing.
     */
    public function visible(Tool $tool): bool;

    /**
     * Null when the call may go ahead, or the refusal to send back.
     *
     * Checked again at call time even for a visible tool: rights can change
     * between the listing and the call, and a mode passes arguments the
     * listing never saw.
     *
     * @param array<string, mixed> $arguments
     */
    public function authorize(Tool $tool, array $arguments): ?CallResult;

    /**
     * Runs the tool. Only ever called after authorize() returned null.
     *
     * @param array<string, mixed> $arguments
     */
    public function call(Tool $tool, array $arguments): CallResult;
}
