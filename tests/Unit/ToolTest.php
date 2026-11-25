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

use Novalo\McpToolDiscovery\Exception\InvalidTool;
use Novalo\McpToolDiscovery\Tool\Tool;
use Novalo\McpToolDiscovery\Tool\ToolSet;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ToolTest extends TestCase
{
    public function testKeepsTheDefinitionAsGiven(): void
    {
        $definition = ['name' => 'get_file', 'inputSchema' => ['type' => 'object'], 'x-custom' => 1];

        self::assertSame($definition, Tool::fromArray($definition)->toArray());
    }

    public function testTheTitleFallsBackToTheAnnotationsThenTheName(): void
    {
        self::assertSame('Get', Tool::fromArray(['name' => 'a', 'title' => 'Get', 'inputSchema' => ['type' => 'object']])->title());
        self::assertSame('Old', Tool::fromArray(['name' => 'a', 'annotations' => ['title' => 'Old'], 'inputSchema' => ['type' => 'object']])->title());
        self::assertSame('a', Tool::fromArray(['name' => 'a', 'inputSchema' => ['type' => 'object']])->title());
    }

    public function testOnlyAnExplicitReadOnlyHintIsARead(): void
    {
        $tool = static fn(array $annotations): Tool => Tool::fromArray(['name' => 'a', 'inputSchema' => ['type' => 'object'], 'annotations' => $annotations]);

        self::assertTrue($tool(['readOnlyHint' => true])->readsOnly());
        self::assertFalse($tool([])->readsOnly());
        self::assertFalse($tool(['readOnlyHint' => 'yes'])->readsOnly());
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function invalidDefinitions(): iterable
    {
        yield 'no name' => [['inputSchema' => ['type' => 'object']]];
        yield 'a space in the name' => [['name' => 'get file', 'inputSchema' => ['type' => 'object']]];
        yield 'a newline after the name' => [['name' => "get_file\n", 'inputSchema' => ['type' => 'object']]];
        yield 'a name too long' => [['name' => str_repeat('a', 129), 'inputSchema' => ['type' => 'object']]];
        yield 'no input schema' => [['name' => 'a']];
        yield 'a schema that is not an object' => [['name' => 'a', 'inputSchema' => ['type' => 'string']]];
        yield 'a title that is not a string' => [['name' => 'a', 'title' => 1, 'inputSchema' => ['type' => 'object']]];
        yield 'annotations that are not an object' => [['name' => 'a', 'annotations' => 'x', 'inputSchema' => ['type' => 'object']]];
    }

    /**
     * @param array<string, mixed> $definition
     */
    #[DataProvider('invalidDefinitions')]
    public function testRefusesAMalformedDefinition(array $definition): void
    {
        $this->expectException(InvalidTool::class);
        Tool::fromArray($definition);
    }

    public function testASetRefusesTheSameNameTwice(): void
    {
        $this->expectException(InvalidTool::class);
        ToolSet::of([
            ['name' => 'a', 'inputSchema' => ['type' => 'object']],
            ['name' => 'a', 'inputSchema' => ['type' => 'object']],
        ]);
    }

    public function testASetHandsARefusedToolToTheHostAndKeepsTheOthers(): void
    {
        $badName = ['name' => 'get file', 'inputSchema' => ['type' => 'object']];
        $later = ['name' => 'a', 'description' => 'Second', 'inputSchema' => ['type' => 'object']];
        $twice = Tool::fromArray(['name' => 'b', 'description' => 'Second', 'inputSchema' => ['type' => 'object']]);
        $refused = [];

        $set = ToolSet::of([
            ['name' => 'a', 'description' => 'First', 'inputSchema' => ['type' => 'object']],
            $badName,
            ['name' => 'b', 'description' => 'First', 'inputSchema' => ['type' => 'object']],
            $later,
            $twice,
            ['name' => 'c', 'inputSchema' => ['type' => 'string']],
        ], static function (InvalidTool $error, Tool|array $definition) use (&$refused): void {
            $refused[] = [$error->getMessage(), $definition];
        });

        self::assertSame(['a', 'b'], $set->names());
        self::assertSame('First', $set->get('a')?->description(), 'the first tool under a name is kept');
        self::assertSame('First', $set->get('b')?->description());
        self::assertCount(4, $refused);
        self::assertSame($badName, $refused[0][1], 'the host gets back what it gave');
        self::assertStringContainsString('the name must be', $refused[0][0]);
        self::assertSame($later, $refused[1][1]);
        self::assertSame('Tool "a": the name is used twice', $refused[1][0]);
        self::assertSame($twice, $refused[2][1]);
        self::assertStringContainsString('Tool "c": inputSchema', $refused[3][0]);
    }

    public function testASetKeepsTheHostOrder(): void
    {
        $set = ToolSet::of([
            ['name' => 'z', 'inputSchema' => ['type' => 'object']],
            ['name' => 'a', 'inputSchema' => ['type' => 'object']],
        ]);

        self::assertSame(['z', 'a'], $set->names());
        self::assertSame(['a'], $set->filter(static fn(Tool $tool): bool => $tool->name === 'a')->names());
        self::assertSame(['z', 'a'], $set->names(), 'filter() returns a new set');
    }

    public function testANumericNameStaysAString(): void
    {
        // PHP keys "123" as an integer: the set must still hand out strings.
        $set = ToolSet::of([
            ['name' => '123', 'inputSchema' => ['type' => 'object']],
            ['name' => 'a', 'inputSchema' => ['type' => 'object']],
        ]);

        self::assertSame(['123', 'a'], $set->names());
        $keys = [];
        foreach ($set as $name => $tool) {
            $keys[] = $name;
            self::assertSame($tool->name, $name);
        }
        self::assertSame(['123', 'a'], $keys);
        self::assertSame('123', $set->get('123')?->name);
        self::assertSame(['123'], $set->filter(static fn(Tool $tool): bool => $tool->name === '123')->names());
    }
}
