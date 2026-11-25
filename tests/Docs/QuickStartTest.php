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

namespace Novalo\McpToolDiscovery\Tests\Docs;

use PHPUnit\Framework\TestCase;

/**
 * Runs the quick start of the README as it is written. DocsTest reads every
 * snippet without running it; that catches a renamed class or argument, but
 * not a gate that no longer fits ToolGate, a result in another shape, or
 * blocks that only work alone. The quick start is the page a host copies
 * first, so it is the one that must run.
 *
 * The host code it names (a user, a tool registry, the decoded params) is
 * given here with the least the snippets ask of it, and the whole script runs
 * in its own process, so its classes never meet the test run's.
 */
final class QuickStartTest extends TestCase
{
    use RunsSnippets;

    // What the snippets take from the host, and nothing more: `can()` with
    // or without arguments, `run()` returning an MCP CallToolResult array.
    // ModeSnippetsTest gives the README's mode snippets the same host.
    public const HOST = <<<'PHP'
        final class User
        {
            /** @param list<string> $may */
            public function __construct(private array $may) {}

            public function can(string $name, array $arguments = []): bool
            {
                return in_array($name, $this->may, true);
            }
        }

        final class MyTools
        {
            public function run(string $name, array $arguments): array
            {
                return ['content' => [['type' => 'text', 'text' => 'ran ' . $name]]];
            }
        }

        $user = new User(['files_read']);
        $myTools = new MyTools();
        $myToolDefinitions = [
            ['name' => 'files_read', 'description' => 'Read a file.', 'inputSchema' => ['type' => 'object']],
            ['name' => 'files_delete', 'description' => 'Delete a file.', 'inputSchema' => ['type' => 'object']],
        ];
        $params = ['protocolVersion' => '2025-11-25', 'name' => 'files_read', 'arguments' => []];
        PHP;

    public function testTheQuickStartRunsAsWritten(): void
    {
        $blocks = self::blocksOf('README.md', '## Quick start');
        // The gate, the tools, the two methods: a quick start with fewer
        // blocks has lost a step a host needs.
        self::assertGreaterThanOrEqual(3, \count($blocks));

        // The first three blocks answer a client; what they answered is kept
        // before the optional search block builds its own Discovery, which is
        // only built, so its cache and store paths are never written.
        $script = "<?php\n\n" . self::autoload() . "\n"
            . self::HOST . "\n"
            . implode("\n", \array_slice($blocks, 0, 3)) . "\n"
            . '$answers = [$revision->value, array_column($discovery->listTools($tools, $revision)[\'tools\'], \'name\'), $result, '
            . '$discovery->callTool(\'files_delete\', [], $tools, $revision), $initialize];' . "\n"
            . implode("\n", \array_slice($blocks, 3)) . "\n"
            . 'echo json_encode($answers, JSON_THROW_ON_ERROR);' . "\n";

        [$code, $out, $err] = self::runScript($script);

        self::assertSame(0, $code, $err . $out);
        self::assertSame('', $err);
        $answers = json_decode($out, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($answers);
        [$revision, $listed, $called, $hidden, $initialize] = $answers;

        self::assertSame('2025-11-25', $revision);
        // The compact mode's tools, never the host's own.
        self::assertSame(['search_tools', 'run_read_tool', 'run_write_tool'], $listed);
        // A visible tool called by its name reaches the host's registry.
        self::assertSame(['content' => [['type' => 'text', 'text' => 'ran files_read']]], $called);
        // A tool the user may not see is never run, as the snippet's comment says.
        self::assertIsArray($hidden);
        self::assertTrue($hidden['isError'] ?? false);
        self::assertStringNotContainsString('ran files_delete', json_encode($hidden, JSON_THROW_ON_ERROR));

        // The handshake a host copies answers in the negotiated revision and
        // carries the mode's instructions after the host's words: without
        // them the model reads three tools as the whole server.
        self::assertIsArray($initialize);
        self::assertSame('2025-11-25', $initialize['protocolVersion'] ?? null);
        self::assertSame([], $initialize['capabilities']['tools'] ?? null);
        $instructions = $initialize['instructions'] ?? null;
        self::assertIsString($instructions);
        self::assertStringStartsWith('Files and tickets of the team. ', $instructions);
        self::assertStringContainsString('search_tools', $instructions);
        self::assertStringNotContainsString('files_read', $instructions);
    }
}
