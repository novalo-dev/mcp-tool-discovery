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

/**
 * A tool definition the library cannot work with.
 *
 * Thrown while the host builds its tool set, or when the set first meets a
 * mode whose own tools would hide one of it, whatever the client asks: a bad
 * definition is a bug of the host, and failing loudly keeps it from surfacing
 * later as a confusing answer to an assistant, or as a tool nobody can reach.
 */
final class InvalidTool extends \InvalidArgumentException
{
    public static function because(string $name, string $reason): self
    {
        return new self(sprintf('Tool "%s": %s', $name, $reason));
    }
}
