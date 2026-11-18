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
 * Runs every snippet that builds a mode, in the README and in docs/MODES.md,
 * and calls each tool of the mode it builds as a client would. A snippet can
 * name only real classes and arguments and still break the host that copies
 * it: a `groupOf` that read `$myGroups[$t->name]` with no fallback passed
 * DocsTest, then threw a TypeError from the first search with no argument on
 * a server with one tool outside its groups.
 *
 * Each page runs as one script, its `use` lines on top, as a reader who
 * copies the page has them. Each snippet ends on `$mode = ...`, and the mode
 * it gives is probed before the next snippet replaces it.
 */
final class ModeSnippetsTest extends TestCase
{
    use RunsSnippets;

    // What the mode snippets name beyond the quick start's host: a map of
    // groups that leaves two tools out, as a real one does, and a setting.
    // The probe gives every mode the same tools and a gate that hides one,
    // then calls each listed tool with the arguments the docs show and the
    // ones each mode documents, so every path a client takes is walked.
    private const PROBE = <<<'PHP'
        $myGroups = ['acme_get_file' => 'files', 'acme_move_file' => 'files', 'acme_list_events' => 'calendar'];
        $settings = (object) ['compact' => true];

        $probeTools = \Novalo\McpToolDiscovery\Tool\ToolSet::of([
            ['name' => 'acme_ping', 'description' => 'Check the server answers.', 'inputSchema' => ['type' => 'object'], 'annotations' => ['readOnlyHint' => true]],
            ['name' => 'acme_get_file', 'title' => 'Get a file', 'description' => 'Read one file.', 'inputSchema' => ['type' => 'object', 'properties' => ['file_id' => ['type' => 'string']]], 'annotations' => ['readOnlyHint' => true]],
            ['name' => 'acme_move_file', 'title' => 'Move a file', 'description' => 'Move a file to a folder.', 'inputSchema' => ['type' => 'object']],
            ['name' => 'acme_list_events', 'title' => 'List calendar events', 'description' => 'List the events of a calendar.', 'inputSchema' => ['type' => 'object'], 'annotations' => ['readOnlyHint' => true]],
            ['name' => 'acme_set_ticket_status', 'title' => 'Change a ticket status', 'description' => 'Set the status of a ticket.', 'inputSchema' => ['type' => 'object', 'properties' => ['ticket_id' => ['type' => 'string'], 'status' => ['type' => 'string']]]],
            ['name' => 'acme_delete_file', 'title' => 'Delete a file', 'description' => 'Delete a file for good.', 'inputSchema' => ['type' => 'object']],
        ]);
        $probeGate = new class implements \Novalo\McpToolDiscovery\Gate\ToolGate {
            public function visible(\Novalo\McpToolDiscovery\Tool\Tool $tool): bool
            {
                return $tool->name !== 'acme_delete_file';
            }

            public function authorize(\Novalo\McpToolDiscovery\Tool\Tool $tool, array $arguments): ?\Novalo\McpToolDiscovery\Gate\CallResult
            {
                return null;
            }

            public function call(\Novalo\McpToolDiscovery\Tool\Tool $tool, array $arguments): \Novalo\McpToolDiscovery\Gate\CallResult
            {
                return \Novalo\McpToolDiscovery\Gate\CallResult::text('ran ' . $tool->name);
            }
        };
        $probeArguments = [
            [],
            ['query' => 'file'],
            ['action' => 'move', 'object' => 'file'],
            ['names' => ['acme_get_file', 'acme_delete_file']],
            ['name' => 'acme_get_file', 'arguments' => ['file_id' => '42']],
            ['name' => 'acme_move_file', 'arguments' => []],
            ['operation' => 'search'],
            ['operation' => 'search', 'query' => 'file'],
            ['operation' => 'describe', 'names' => ['acme_get_file', 'acme_delete_file']],
            ['operation' => 'run', 'name' => 'acme_get_file', 'arguments' => []],
            ['action' => 'acme_get_file', 'arguments' => []],
            ['action' => 'acme_move_file', 'arguments' => []],
            ['describe' => ['acme_get_file', 'acme_delete_file']],
            ...$pageArguments,
        ];
        $probe = static function (\Novalo\McpToolDiscovery\Mode\DiscoveryMode $mode) use ($probeTools, $probeGate, $probeArguments): array {
            $discovery = new \Novalo\McpToolDiscovery\Discovery($mode, $probeGate);
            $listed = [];
            $answers = [];
            foreach (\Novalo\McpToolDiscovery\Protocol\Revision::cases() as $revision) {
                $listed = array_column($discovery->listTools($probeTools, $revision)['tools'], 'name');
                foreach ($listed as $name) {
                    foreach ($probeArguments as $arguments) {
                        $answers[] = json_encode($discovery->callTool($name, $arguments, $probeTools, $revision), JSON_THROW_ON_ERROR);
                    }
                }
            }

            return ['mode' => $mode->name(), 'listed' => $listed, 'answers' => $answers];
        };
        $probed = [];
        PHP;

    public function testEveryModeSnippetOfTheReadmeBuildsAModeAClientCanUse(): void
    {
        // The README's modes lean on the gate of its quick start and on the
        // `use` lines it shows: a reader has read it first.
        $quickStart = self::blocksOf('README.md', '## Quick start');
        self::assertModeSnippetsRun('README.md', ['## Modes'], [$quickStart[0]], $quickStart);
    }

    public function testEveryModeSnippetOfTheModesPageBuildsAModeAClientCanUse(): void
    {
        self::assertModeSnippetsRun('docs/MODES.md', ['## Grouped', '## Gateway', '## Direct'], [], []);
    }

    /**
     * @param list<string> $sections
     * @param list<string> $before   blocks of the page that run first, unprobed
     * @param list<string> $imports  blocks whose `use` lines the page relies on
     */
    private static function assertModeSnippetsRun(string $page, array $sections, array $before, array $imports): void
    {
        $blocks = [];
        $json = [];
        foreach ($sections as $heading) {
            foreach (self::blocksOf($page, $heading) as $block) {
                if (preg_match('/new \w+Mode\(/', $block) === 1) {
                    $blocks[] = $block;
                }
            }
            $json = [...$json, ...self::jsonLinesOf($page, $heading)];
        }
        self::assertNotSame([], $blocks, $page . ' shows no snippet that builds a mode.');

        $uses = [];
        $body = '';
        foreach ([...$imports, ...$before] as $block) {
            $uses = [...$uses, ...self::uses($block)];
        }
        foreach ($before as $block) {
            $body .= self::withoutUses($block) . "\n";
        }
        foreach ($blocks as $i => $block) {
            // A snippet that leaves its mode in no variable shows the host
            // nothing to hand to Discovery, and leaves this test nothing to run.
            self::assertMatchesRegularExpression('/\$mode = /', $block, $page . ' mode snippet ' . ($i + 1) . ' gives no $mode.');
            $uses = [...$uses, ...self::uses($block)];
            $body .= self::withoutUses($block) . "\n" . '$probed[] = $probe($mode);' . "\n";
        }

        $script = "<?php\n\n" . self::autoload() . "\n"
            . implode("\n", array_values(array_unique($uses))) . "\n\n"
            . QuickStartTest::HOST . "\n"
            . '$pageArguments = ' . var_export($json, true) . ";\n"
            . self::PROBE . "\n"
            . $body
            . 'echo json_encode($probed, JSON_THROW_ON_ERROR);' . "\n";

        [$code, $out, $err] = self::runScript($script);

        self::assertSame(0, $code, $err . $out);
        // A warning or a deprecation in a snippet is what the host sees too.
        self::assertSame('', $err);
        $probed = json_decode($out, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($probed);
        self::assertCount(\count($blocks), $probed);

        foreach ($probed as $i => $run) {
            self::assertIsArray($run);
            $where = $page . ' mode snippet ' . ($i + 1) . ' (' . $run['mode'] . ')';
            self::assertIsArray($run['listed']);
            self::assertNotSame([], $run['listed'], $where . ' lists nothing.');
            self::assertNotContains('acme_delete_file', $run['listed'], $where . ' lists a hidden tool.');
            $answers = implode("\n", $run['answers']);
            // The mode reaches the host's tools, and never the hidden one.
            self::assertStringContainsString('ran acme_get_file', $answers, $where . ' never runs a tool.');
            self::assertStringNotContainsString('ran acme_delete_file', $answers, $where);
        }
    }

    /**
     * The `use` lines of a block.
     *
     * @return list<string>
     */
    private static function uses(string $block): array
    {
        preg_match_all('/^use [^;]+;$/m', $block, $lines);

        return $lines[0];
    }

    private static function withoutUses(string $block): string
    {
        return trim((string) preg_replace('/^use [^;]+;\n/m', '', $block));
    }

    /**
     * The arguments the section's JSON blocks show, one object per line.
     *
     * @return list<array<mixed>>
     */
    private static function jsonLinesOf(string $page, string $heading): array
    {
        $all = [];
        foreach (self::blocksOf($page, $heading, 'json') as $block) {
            foreach (array_filter(explode("\n", $block)) as $line) {
                $arguments = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
                self::assertIsArray($arguments);
                $all[] = $arguments;
            }
        }

        return $all;
    }
}
