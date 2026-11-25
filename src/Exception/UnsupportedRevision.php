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

namespace Novalo\McpToolDiscovery\Exception;

use Novalo\McpToolDiscovery\Gate\ToolRunner;

/**
 * A protocol revision this library does not know how to shape answers for.
 *
 * The host decides what to do with it, so this is an exception it catches,
 * not a silent fallback that would hide a new revision. At `initialize` the
 * specification already says what to do, and Revision::negotiate() does it
 * without throwing.
 *
 * The revision comes from the client, and a host may well write this message
 * to its log: it is cut short and kept to the characters a revision is made
 * of, so a client cannot write a line break or a terminal sequence there.
 */
final class UnsupportedRevision extends \InvalidArgumentException
{
    public static function named(string $revision): self
    {
        return new self(sprintf('Unsupported MCP protocol revision "%s".', ToolRunner::printable($revision)));
    }
}
