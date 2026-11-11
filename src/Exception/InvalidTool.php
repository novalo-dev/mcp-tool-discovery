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
 * Thrown while the host builds its tool set, never while a client calls: a
 * malformed definition is a bug of the host, and failing at build time keeps
 * it from surfacing later as a confusing answer to an assistant.
 */
final class InvalidTool extends \InvalidArgumentException
{
    public static function because(string $name, string $reason): self
    {
        return new self(sprintf('Tool "%s": %s', $name, $reason));
    }
}
