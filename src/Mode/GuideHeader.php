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

use Novalo\McpToolDiscovery\Tool\ToolSet;

/**
 * A mode that can say, at the top of a guide, how to reach the tools the
 * guide names.
 *
 * A guide (an MCP prompt) names host tools, and a mode that does not list
 * them as themselves leaves the assistant looking for tools it cannot see.
 * Apart from DiscoveryMode, so a mode written elsewhere keeps working
 * without it, and a mode that lists every tool as itself has nothing to
 * write. Hosts call Discovery::guideHeader(), which gives the mode only the
 * tools the caller may see and answers "" for a mode without a header.
 */
interface GuideHeader
{
    /**
     * The block to put at the top of the guide's text, ending with a blank
     * line, or "" when every tool it names is in the list as itself. Names
     * only tools of $visible.
     *
     * @param iterable<string> $names the tool names the guide uses
     */
    public function guideHeader(iterable $names, ToolSet $visible): string;
}
