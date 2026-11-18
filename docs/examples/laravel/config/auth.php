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

// The guard the MCP route reads its caller from. A real application adds
// it to the guards of its own config/auth.php; Laravel merges this list
// with its defaults, so the session guard of the web routes stays as it is.
return [
    'guards' => [
        'mcp' => ['driver' => 'mcp-token'],
    ],
];
