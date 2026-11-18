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

/*
 * The notes server of stdio-server.php, served over HTTP the way most PHP
 * applications run: one process per request, behind PHP-FPM or any web
 * server. What it answers is in http.php; this file only reads the request
 * from PHP's globals, says what one request may keep for the next, and sends
 * the answer. The tests run it with PHP's built-in server.
 *
 * Run it:  php -S 127.0.0.1:8080 docs/examples/http-server.php
 * Call it: curl -s http://127.0.0.1:8080/mcp -H 'Authorization: Bearer reader-token' \
 *            -H 'MCP-Protocol-Version: 2025-11-25' \
 *            -d '{"jsonrpc":"2.0","id":1,"method":"tools/list"}'
 */

namespace Example\Notes;

use Novalo\McpToolDiscovery\Discovery;
use Novalo\McpToolDiscovery\Mode\Compact\CompactMode;
use Novalo\McpToolDiscovery\Search\Cache\FileIndexCache;
use Novalo\McpToolDiscovery\Search\SearchSetup;

// In a project of your own: require 'vendor/autoload.php'.
require dirname(__DIR__, 2) . '/vendor/autoload.php';
require __DIR__ . '/notes.php';
require __DIR__ . '/http.php';

$server = $_SERVER;
// PHP hands a header over as HTTP_ plus its name in capitals, dashes as
// underscores.
$header = static function (string $name) use ($server): ?string {
    $value = $server['HTTP_' . strtoupper(str_replace('-', '_', $name))] ?? null;

    return is_string($value) ? $value : null;
};

// Each request is a new process, so nothing in memory reaches the next one:
// the notes are kept in a file, and the index cache saves building the search
// index again each time a client searches.
$discovery = static function () use ($header): ?Discovery {
    $role = roleOf($header);
    if ($role === null) {
        return null;
    }
    $data = getenv('MCP_NOTES_DATA');
    // A directory only this server writes to: the index cache runs its files.
    $data = is_string($data) && $data !== '' ? $data : __DIR__ . '/var';
    if (!is_dir($data)) {
        mkdir($data, 0o700, true);
    }

    return new Discovery(
        new CompactMode(prefix: 'notes_', batchLimit: 20),
        new NotesGate($role, new Notes($data . '/notes.json')),
        new SearchSetup(cache: new FileIndexCache($data . '/index')),
    );
};

$method = is_string($server['REQUEST_METHOD'] ?? null) ? $server['REQUEST_METHOD'] : '';
[$status, $body, $headers] = answerHttp($method, $header, (string) file_get_contents('php://input'), $discovery);

http_response_code($status);
foreach ($headers as $name => $value) {
    header($name . ': ' . $value);
}
if ($body !== null) {
    header('Content-Type: application/json');
    echo encode($body);
}
