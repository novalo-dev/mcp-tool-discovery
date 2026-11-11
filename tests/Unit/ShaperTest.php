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

use Novalo\McpToolDiscovery\Exception\UnsupportedRevision;
use Novalo\McpToolDiscovery\Gate\CallResult;
use Novalo\McpToolDiscovery\Protocol\Revision;
use Novalo\McpToolDiscovery\Protocol\Shaper;
use Novalo\McpToolDiscovery\Tool\Tool;
use PHPUnit\Framework\TestCase;

final class ShaperTest extends TestCase
{
    private static function richTool(): Tool
    {
        return Tool::fromArray([
            'name' => 'get_file',
            'title' => 'Get a file',
            'description' => 'Returns one file.',
            'inputSchema' => ['type' => 'object'],
            'outputSchema' => ['type' => 'object'],
            'annotations' => ['readOnlyHint' => true],
            'icons' => [['src' => 'https://example.com/i.png']],
            'execution' => ['taskSupport' => 'forbidden'],
            '_meta' => ['x' => 1],
            'x-host-field' => 'never sent',
        ]);
    }

    public function testTheOldestRevisionGetsTheThreeFieldsItKnows(): void
    {
        self::assertSame(
            ['name', 'description', 'inputSchema'],
            array_keys(Shaper::tool(self::richTool(), Revision::V2024_11_05)),
        );
    }

    public function testTheTitleMovesToTheAnnotationsWhereARevisionHadItThere(): void
    {
        $shaped = Shaper::tool(self::richTool(), Revision::V2025_03_26);

        self::assertArrayNotHasKey('title', $shaped);
        self::assertSame(['title' => 'Get a file', 'readOnlyHint' => true], $shaped['annotations']);
    }

    public function testAHostFieldIsNeverSent(): void
    {
        foreach (Revision::cases() as $revision) {
            self::assertArrayNotHasKey('x-host-field', Shaper::tool(self::richTool(), $revision), $revision->value);
        }
    }

    public function testExecutionOnlyExistsIn20251125(): void
    {
        self::assertArrayHasKey('execution', Shaper::tool(self::richTool(), Revision::V2025_11_25));
        self::assertArrayNotHasKey('execution', Shaper::tool(self::richTool(), Revision::V2026_07_28));
    }

    public function testTheStatelessRevisionSaysHowLongAListMayBeKept(): void
    {
        $result = Shaper::listResult([self::richTool()], Revision::V2026_07_28, 300000);

        self::assertSame(300000, $result['ttlMs']);
        self::assertSame('private', $result['cacheScope'], 'a list filtered by rights is private by default');
        self::assertSame('complete', $result['resultType']);
        self::assertArrayNotHasKey('ttlMs', Shaper::listResult([], Revision::V2025_11_25, 300000));
    }

    public function testStructuredContentIsLeftOutWhereARevisionLacksIt(): void
    {
        $result = CallResult::json(['a' => 1]);

        self::assertArrayNotHasKey('structuredContent', Shaper::callResult($result, Revision::V2025_03_26));
        self::assertSame(['a' => 1], Shaper::callResult($result, Revision::V2025_06_18)['structuredContent']);
        self::assertSame('complete', Shaper::callResult($result, Revision::V2026_07_28)['resultType']);
    }

    public function testAnUnknownRevisionIsAnError(): void
    {
        $this->expectException(UnsupportedRevision::class);
        Revision::fromString('2099-01-01');
    }
}
