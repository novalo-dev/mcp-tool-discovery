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
        $definition = ['name' => 'get_order', 'inputSchema' => ['type' => 'object'], 'x-custom' => 1];

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
        yield 'a space in the name' => [['name' => 'get order', 'inputSchema' => ['type' => 'object']]];
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
}
