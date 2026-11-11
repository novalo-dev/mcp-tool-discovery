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

use Novalo\McpToolDiscovery\Gate\CallResult;
use Novalo\McpToolDiscovery\Gate\ToolGate;
use Novalo\McpToolDiscovery\Tool\Tool;
use Novalo\McpToolDiscovery\Tool\ToolSet;

/**
 * A gate for tests: hides and refuses what it is told to, and records every
 * question it is asked, so a test can prove what a mode did and did not do.
 */
final class RecordingGate implements ToolGate
{
    /** @var list<string> names asked about through visible() */
    public array $seen = [];

    /** @var list<string> names asked about through authorize() */
    public array $authorized = [];

    /** @var list<array{name: string, arguments: array<string, mixed>}> */
    public array $called = [];

    /**
     * @param list<string> $hidden tools visible() refuses
     * @param list<string> $refused tools authorize() refuses
     */
    public function __construct(
        private readonly array $hidden = [],
        private readonly array $refused = [],
    ) {}

    public function visible(Tool $tool): bool
    {
        $this->seen[] = $tool->name;

        return !in_array($tool->name, $this->hidden, true);
    }

    public function authorize(Tool $tool, array $arguments): ?CallResult
    {
        $this->authorized[] = $tool->name;

        return in_array($tool->name, $this->refused, true) ? CallResult::error('Not allowed.') : null;
    }

    public function call(Tool $tool, array $arguments): CallResult
    {
        $this->called[] = ['name' => $tool->name, 'arguments' => $arguments];

        return CallResult::json(['ran' => $tool->name]);
    }

    /**
     * The fixture tools of a fictional store server, the same ones the search
     * benchmark uses.
     */
    public static function storeTools(): ToolSet
    {
        $json = file_get_contents(__DIR__ . '/tools.json');
        if ($json === false) {
            throw new \RuntimeException('tests/Fixtures/tools.json is missing.');
        }
        /** @var list<array<string, mixed>> $definitions */
        $definitions = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

        return ToolSet::of($definitions);
    }
}
