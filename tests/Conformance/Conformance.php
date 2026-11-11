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

namespace Novalo\McpToolDiscovery\Tests\Conformance;

use Novalo\McpToolDiscovery\Discovery;
use Novalo\McpToolDiscovery\Mode\Compact\CompactMode;
use Novalo\McpToolDiscovery\Protocol\Revision;
use Novalo\McpToolDiscovery\Tests\Fixtures\RecordingGate;
use Opis\JsonSchema\Validator;

/**
 * Checks every answer the library builds against the official schema.json of
 * each MCP revision, as published in the specification repository.
 *
 * The checks are a fixed list, so a score is "passing checks out of total"
 * per revision, the way the official MCP SDKs report their conformance. The
 * schemas are downloaded from a pinned commit by bin/fetch-schemas.php: the
 * score changes when the code changes, never because upstream moved.
 */
final class Conformance
{
    /**
     * The commit of modelcontextprotocol/modelcontextprotocol the schemas are
     * read from. Moving it is a change of its own, with its own pull request.
     */
    public const SPEC_COMMIT = '0b22bfed6e94c0c7751046d2e845fac954cac8a7';

    public static function schemaPath(Revision $revision): string
    {
        return dirname(__DIR__, 2) . '/build/schema/' . $revision->value . '.json';
    }

    /**
     * Every check of one revision: a name and the result to validate against
     * a definition of its schema.
     *
     * @return array<string, array{definition: string, value: mixed}>
     */
    public static function checks(Revision $revision): array
    {
        $tools = RecordingGate::storeTools();
        $checks = [];
        $modes = [
            'compact' => new CompactMode(),
            'compact with prefix and pin' => new CompactMode(prefix: 'acme_', pinned: ['acme_get_settings']),
        ];
        foreach ($modes as $label => $mode) {
            $discovery = new Discovery($mode, new RecordingGate(['acme_list_users'], ['acme_delete_customer']));
            $own = static fn(string $name): string => $label === 'compact' ? $name : 'acme_' . $name;
            $checks[$label . ': tools/list'] = ['definition' => 'ListToolsResult', 'value' => $discovery->listTools($tools, $revision, 60000)];
            $checks[$label . ': search with a query'] = ['definition' => 'CallToolResult', 'value' => $discovery->callTool($own(CompactMode::SEARCH), ['query' => 'order status'], $tools, $revision)];
            $checks[$label . ': search by names'] = ['definition' => 'CallToolResult', 'value' => $discovery->callTool($own(CompactMode::SEARCH), ['names' => ['acme_get_order', 'acme_nope']], $tools, $revision)];
            $checks[$label . ': search with no argument'] = ['definition' => 'CallToolResult', 'value' => $discovery->callTool($own(CompactMode::SEARCH), [], $tools, $revision)];
            $checks[$label . ': search with nothing found'] = ['definition' => 'CallToolResult', 'value' => $discovery->callTool($own(CompactMode::SEARCH), ['query' => 'zzzz'], $tools, $revision)];
            $checks[$label . ': read runner'] = ['definition' => 'CallToolResult', 'value' => $discovery->callTool($own(CompactMode::RUN_READ), ['name' => 'acme_get_order', 'arguments' => ['order_id' => '1']], $tools, $revision)];
            $checks[$label . ': change runner'] = ['definition' => 'CallToolResult', 'value' => $discovery->callTool($own(CompactMode::RUN_WRITE), ['name' => 'acme_set_order_status', 'arguments' => []], $tools, $revision)];
            $checks[$label . ': wrong runner'] = ['definition' => 'CallToolResult', 'value' => $discovery->callTool($own(CompactMode::RUN_READ), ['name' => 'acme_delete_post'], $tools, $revision)];
            $checks[$label . ': refused by the host'] = ['definition' => 'CallToolResult', 'value' => $discovery->callTool($own(CompactMode::RUN_WRITE), ['name' => 'acme_delete_customer'], $tools, $revision)];
            $checks[$label . ': hidden tool'] = ['definition' => 'CallToolResult', 'value' => $discovery->callTool($own(CompactMode::RUN_READ), ['name' => 'acme_list_users'], $tools, $revision)];
            $checks[$label . ': host tool by its own name'] = ['definition' => 'CallToolResult', 'value' => $discovery->callTool('acme_get_order', [], $tools, $revision)];
            $checks[$label . ': malformed run'] = ['definition' => 'CallToolResult', 'value' => $discovery->callTool($own(CompactMode::RUN_READ), ['arguments' => 'x'], $tools, $revision)];
        }

        return $checks;
    }

    /**
     * Runs the checks of one revision.
     *
     * @return array{passed: int, total: int, failures: list<string>}
     */
    public static function run(Revision $revision): array
    {
        $path = self::schemaPath($revision);
        $schema = is_file($path) ? file_get_contents($path) : false;
        if ($schema === false) {
            throw new \RuntimeException(sprintf('Missing %s: run php bin/fetch-schemas.php first.', $path));
        }
        $validator = new Validator();
        $validator->setMaxErrors(5);
        $root = 'https://modelcontextprotocol.io/schema/' . $revision->value . '.json';
        $validator->resolver()?->registerRaw($schema, $root);
        $pointer = str_contains($schema, '"$defs"') ? '#/$defs/' : '#/definitions/';

        $passed = 0;
        $failures = [];
        $checks = self::checks($revision);
        foreach ($checks as $name => $check) {
            // Through JSON and back, as a client receives it: empty arrays
            // become objects or lists exactly as on the wire.
            $wire = json_decode(json_encode($check['value'], JSON_THROW_ON_ERROR), false, 512, JSON_THROW_ON_ERROR);
            $result = $validator->validate($wire, $root . $pointer . $check['definition']);
            if ($result->isValid()) {
                ++$passed;
            } else {
                $error = $result->error();
                $failures[] = $name . ': ' . ($error === null ? 'invalid' : $error->message() . ' at /' . implode('/', $error->data()->fullPath()));
            }
        }

        return ['passed' => $passed, 'total' => count($checks), 'failures' => $failures];
    }
}
