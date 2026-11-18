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

// Where the notes and the index cache live. Read through config, as
// Laravel asks for every variable of the environment, so a cached
// configuration still finds it.
return [
    'data' => env('MCP_NOTES_DATA', storage_path('app/mcp')),
];
