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
 * The entry point of Psalm's taint analysis (tests/Security/psalm.xml).
 *
 * A host passes the library what a client sent: the name and arguments of a
 * tools/call. Psalm cannot know that from the library alone, so this file
 * hands it request data, through the mode and with the cache and the
 * learning store on files, the paths the analysis must follow. It is never
 * run.
 */

use Novalo\McpToolDiscovery\Discovery;
use Novalo\McpToolDiscovery\Gate\CallResult;
use Novalo\McpToolDiscovery\Gate\ToolGate;
use Novalo\McpToolDiscovery\Mode\Compact\CompactMode;
use Novalo\McpToolDiscovery\Protocol\Revision;
use Novalo\McpToolDiscovery\Search\Cache\FileIndexCache;
use Novalo\McpToolDiscovery\Search\Learning\FileLearningStore;
use Novalo\McpToolDiscovery\Search\SearchSetup;
use Novalo\McpToolDiscovery\Tool\Tool;
use Novalo\McpToolDiscovery\Tool\ToolSet;

$gate = new class implements ToolGate {
    public function visible(Tool $tool): bool
    {
        return true;
    }

    /**
     * @param array<string, mixed> $arguments
     */
    public function authorize(Tool $tool, array $arguments): ?CallResult
    {
        return null;
    }

    /**
     * @param array<string, mixed> $arguments
     */
    public function call(Tool $tool, array $arguments): CallResult
    {
        return CallResult::text('done');
    }
};

$tools = ToolSet::of([
    ['name' => 'example_read', 'description' => 'Reads one record.', 'inputSchema' => ['type' => 'object'], 'annotations' => ['readOnlyHint' => true]],
    ['name' => 'example_write', 'description' => 'Changes one record.', 'inputSchema' => ['type' => 'object']],
]);
$setup = new SearchSetup(
    cache: new FileIndexCache(sys_get_temp_dir() . '/mcp-tool-discovery-index'),
    learning: new FileLearningStore(sys_get_temp_dir() . '/mcp-tool-discovery-learning.json'),
);
$name = $_POST['name'] ?? '';
$arguments = $_POST['arguments'] ?? [];
$result = (new Discovery(new CompactMode(), $gate, $setup))->callTool(
    is_string($name) ? $name : '',
    is_array($arguments) ? $arguments : [],
    $tools,
    Revision::V2026_07_28,
);
