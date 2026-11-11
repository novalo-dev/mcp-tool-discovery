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

namespace Novalo\McpToolDiscovery\Tests\Unit;

use Novalo\McpToolDiscovery\Protocol\Json;
use Novalo\McpToolDiscovery\Protocol\Revision;
use Novalo\McpToolDiscovery\Protocol\Shaper;
use Novalo\McpToolDiscovery\Tool\Tool;
use PHPUnit\Framework\TestCase;

final class JsonTest extends TestCase
{
    public function testAToolWithNoParameterSendsAnObject(): void
    {
        $tool = Tool::fromArray(['name' => 'ping', 'inputSchema' => ['type' => 'object', 'properties' => []], 'annotations' => []]);

        self::assertSame(
            '{"name":"ping","inputSchema":{"type":"object","properties":{}},"annotations":{}}',
            json_encode(Shaper::tool($tool, Revision::V2025_06_18)),
        );
    }

    public function testNestedSchemasAreFixedToo(): void
    {
        $schema = [
            'type' => 'object',
            'properties' => [
                'filter' => ['type' => 'object', 'properties' => []],
                'tags' => ['type' => 'array', 'items' => []],
                'either' => ['anyOf' => [['type' => 'object', 'properties' => []], ['type' => 'null']]],
            ],
        ];

        self::assertSame(
            '{"type":"object","properties":{"filter":{"type":"object","properties":{}},"tags":{"type":"array","items":{}},"either":{"anyOf":[{"type":"object","properties":{}},{"type":"null"}]}}}',
            json_encode(Json::schema($schema)),
        );
    }

    public function testAListStaysAList(): void
    {
        self::assertSame('{"type":"object","required":[]}', json_encode(Json::schema(['type' => 'object', 'required' => []])));
    }
}
