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
 * Downloads the official schema.json of each MCP revision, from the commit
 * the conformance suite is pinned to, into build/schema/.
 */

require dirname(__DIR__) . '/vendor/autoload.php';

use Novalo\McpToolDiscovery\Protocol\Revision;
use Novalo\McpToolDiscovery\Tests\Conformance\Conformance;

foreach (Revision::cases() as $revision) {
    $path = Conformance::schemaPath($revision);
    if (!is_dir(dirname($path)) && !mkdir(dirname($path), 0o777, true) && !is_dir(dirname($path))) {
        fwrite(STDERR, 'Cannot create ' . dirname($path) . "\n");
        exit(1);
    }
    $url = sprintf('https://raw.githubusercontent.com/modelcontextprotocol/modelcontextprotocol/%s/schema/%s/schema.json', Conformance::SPEC_COMMIT, $revision->value);
    $json = @file_get_contents($url);
    if ($json === false || json_decode($json) === null) {
        fwrite(STDERR, 'Cannot download ' . $url . "\n");
        exit(1);
    }
    file_put_contents($path, $json);
    echo $revision->value, ' ', strlen($json), " bytes\n";
}
