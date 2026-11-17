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

namespace Novalo\McpToolDiscovery\Tests\Fixtures;

use Novalo\McpToolDiscovery\Discovery;
use Novalo\McpToolDiscovery\Mode\Compact\CompactMode;
use Novalo\McpToolDiscovery\Mode\Gateway\GatewayMode;
use Novalo\McpToolDiscovery\Protocol\Revision;
use Novalo\McpToolDiscovery\Protocol\Shaper;

/**
 * What each mode costs the client, in bytes of JSON, on the benchmark servers.
 *
 * The list is what a client sends the model with every request, so it is the
 * cost that repeats; a search answer enters the conversation once, when the
 * client looks for a tool. The search is measured on the test half of the
 * benchmark requests, in fields, and only its text content is counted: on
 * the revisions that add structured content the same data travels twice,
 * and a client reads one copy.
 *
 * Bytes rather than tokens, because the count of tokens depends on each
 * model's tokenizer; for English JSON a token is about four bytes.
 *
 * Shared by the test that guards the claims of docs/MODES.md and by
 * bin/benchmark.php, which prints the table.
 */
final class ListCost
{
    /** The latest revision with a handshake, the one most clients speak. */
    public const REVISION = '2025-11-25';

    /**
     * One row per server: its number of tools, the bytes of its whole list
     * (every tool as itself, as a server without the library sends it), of
     * the compact and gateway lists, of the grouped list where the host
     * declares groups (only the workspace does), and the mean bytes of one
     * compact search answer.
     *
     * @return array<string, array{tools: int, all: int, compact: int, gateway: int, grouped: ?int, search: int}>
     */
    public static function run(): array
    {
        $revision = Revision::fromString(self::REVISION);
        $requests = array_values(array_filter(Benchmark::queries(), static fn(array $case): bool => $case['half'] === 'test'));
        $rows = [];
        foreach (Benchmark::SERVERS as $server => $file) {
            $tools = RecordingGate::server($file);
            $compact = new Discovery(new CompactMode(), new RecordingGate());
            $searches = [];
            foreach ($requests as $case) {
                if ($case['server'] === $server) {
                    $answer = $compact->callTool('search_tools', ['action' => $case['action'], 'object' => $case['object'], 'description' => $case['description']], $tools, $revision);
                    $searches[] = self::textBytes($answer);
                }
            }
            $rows[$server] = [
                'tools' => count($tools),
                'all' => self::bytes(Shaper::listResult($tools->all(), $revision)),
                'compact' => self::bytes($compact->listTools($tools, $revision)),
                'gateway' => self::bytes((new Discovery(new GatewayMode(), new RecordingGate()))->listTools($tools, $revision)),
                'grouped' => $server === 'workspace' ? self::bytes((new Discovery(Groups::mode(), new RecordingGate()))->listTools($tools, $revision)) : null,
                'search' => $searches === [] ? 0 : intdiv(array_sum($searches), count($searches)),
            ];
        }

        return $rows;
    }

    /**
     * @param array<string, mixed> $result
     */
    private static function bytes(array $result): int
    {
        // The flags the example servers answer with: a server that escapes
        // slashes and accents sends more, the same for every mode.
        return strlen(json_encode($result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }

    /**
     * @param array<string, mixed> $result
     */
    private static function textBytes(array $result): int
    {
        $bytes = 0;
        foreach (is_array($result['content'] ?? null) ? $result['content'] : [] as $block) {
            if (is_array($block) && is_string($block['text'] ?? null)) {
                $bytes += strlen($block['text']);
            }
        }

        return $bytes;
    }
}
