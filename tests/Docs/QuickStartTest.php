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
    // What the snippets take from the host, and nothing more: `can()` with
    // or without arguments, `run()` returning an MCP CallToolResult array.
    private const HOST = <<<'PHP'
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
        $blocks = self::quickStart();
        // The gate, the tools, the two methods: a quick start with fewer
        // blocks has lost a step a host needs.
        self::assertGreaterThanOrEqual(3, \count($blocks));

        // The first three blocks answer a client; what they answered is kept
        // before the optional search block builds its own Discovery, which is
        // only built, so its cache and store paths are never written.
        $script = "<?php\n\nrequire " . var_export(\dirname(__DIR__, 2) . '/vendor/autoload.php', true) . ";\n\n"
            . self::HOST . "\n"
            . implode("\n", \array_slice($blocks, 0, 3)) . "\n"
            . '$answers = [$revision->value, array_column($discovery->listTools($tools, $revision)[\'tools\'], \'name\'), $result, '
            . '$discovery->callTool(\'files_delete\', [], $tools, $revision)];' . "\n"
            . implode("\n", \array_slice($blocks, 3)) . "\n"
            . 'echo json_encode($answers, JSON_THROW_ON_ERROR);' . "\n";

        $file = sys_get_temp_dir() . '/mcp-tool-discovery-quick-start-' . bin2hex(random_bytes(6)) . '.php';
        file_put_contents($file, $script);

        try {
            [$code, $out, $err] = self::execute($file);
        } finally {
            unlink($file);
        }

        self::assertSame(0, $code, $err . $out);
        self::assertSame('', $err);
        $answers = json_decode($out, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($answers);
        [$revision, $listed, $called, $hidden] = $answers;

        self::assertSame('2025-11-25', $revision);
        // The compact mode's tools, never the host's own.
        self::assertSame(['search_tools', 'run_read_tool', 'run_write_tool'], $listed);
        // A visible tool called by its name reaches the host's registry.
        self::assertSame(['content' => [['type' => 'text', 'text' => 'ran files_read']]], $called);
        // A tool the user may not see is never run, as the snippet's comment says.
        self::assertIsArray($hidden);
        self::assertTrue($hidden['isError'] ?? false);
        self::assertStringNotContainsString('ran files_delete', json_encode($hidden, JSON_THROW_ON_ERROR));
    }

    /**
     * The PHP blocks of the README's Quick start section, in order.
     *
     * @return list<string>
     */
    private static function quickStart(): array
    {
        $readme = (string) file_get_contents(\dirname(__DIR__, 2) . '/README.md');
        $start = strpos($readme, "\n## Quick start\n");
        self::assertNotFalse($start, 'The README has no Quick start section.');
        $end = strpos($readme, "\n## ", $start + 1);
        $section = substr($readme, $start, false === $end ? null : $end - $start);
        preg_match_all('/^```php\n(.*?)^```/ms', $section, $blocks);

        return $blocks[1];
    }

    /**
     * @return array{int, string, string}
     */
    private static function execute(string $file): array
    {
        $process = proc_open([PHP_BINARY, $file], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        self::assertIsResource($process);
        $out = (string) stream_get_contents($pipes[1]);
        $err = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [proc_close($process), $out, $err];
    }
}
