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

/**
 * A gate that decides on the arguments, not the tool: it refuses the calls
 * whose argument `$key` holds one of the given values, and records each
 * question, so a test can prove every item of a batch met the gate on its own.
 */
final class ArgumentGate implements ToolGate
{
    /** @var list<string> the value of `$key` in each call authorize() was asked about */
    public array $authorized = [];

    /** @var list<array<string, mixed>> the arguments of each call that ran */
    public array $called = [];

    /**
     * @param list<string> $refused values of `$key` that authorize() refuses
     */
    public function __construct(
        private readonly string $key,
        private readonly array $refused = [],
    ) {}

    public function visible(Tool $tool): bool
    {
        return true;
    }

    public function authorize(Tool $tool, array $arguments): ?CallResult
    {
        $value = $arguments[$this->key] ?? null;
        $this->authorized[] = is_string($value) ? $value : '';

        return in_array($value, $this->refused, true) ? CallResult::error(sprintf('Not allowed for %s %s.', $this->key, $this->authorized[array_key_last($this->authorized)])) : null;
    }

    public function call(Tool $tool, array $arguments): CallResult
    {
        $this->called[] = $arguments;

        return CallResult::json(['ran' => $tool->name]);
    }
}
