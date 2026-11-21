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
 * A `tools/list` cursor this list did not give, or no longer holds.
 *
 * The specification asks for error -32602 (invalid params). The message
 * never repeats the cursor, which the client chose.
 */
final class InvalidCursor extends \InvalidArgumentException
{
    public static function given(): self
    {
        return new self('Invalid cursor: start the list again without one.');
    }
}
