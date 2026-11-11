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

namespace Novalo\McpToolDiscovery\Search\Learning;

/**
 * What was learned, kept for the life of the PHP process, or of a test.
 */
final class MemoryLearningStore implements LearningStore
{
    /** @var array<string, array<string, int>> */
    private array $counts = [];

    public function load(): array
    {
        return $this->counts;
    }

    public function record(array $words, string $tool): void
    {
        foreach (array_unique($words) as $word) {
            $this->counts[$word][$tool] = ($this->counts[$word][$tool] ?? 0) + 1;
        }
    }
}
