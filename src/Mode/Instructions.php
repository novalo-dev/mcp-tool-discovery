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

namespace Novalo\McpToolDiscovery\Mode;

/**
 * A mode that can say, in the server's instructions, how its list reaches
 * the tools it does not show as themselves.
 *
 * Clients put the `instructions` of the `initialize` answer (or of
 * `server/discover` from 2026-07-28) in the model's system prompt, where it
 * is read before any tool description. A mode that lists three tools for a
 * server of three hundred has to say there that the list is not the whole
 * server, or the model concludes a tool is missing. Apart from
 * DiscoveryMode, so a mode written elsewhere keeps working without it, and
 * a mode that lists every tool as itself has nothing to say. Hosts call
 * Discovery::instructions(), which answers "" for a mode without them.
 */
interface Instructions
{
    /**
     * One or two sentences for the server's instructions, or "".
     *
     * The same for every caller: a `server/discover` answer may be cached
     * for all of them, so it names only the mode's own tools, never one of
     * the host's, which a gate could hide from some.
     */
    public function instructions(): string;
}
