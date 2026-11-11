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

namespace Novalo\McpToolDiscovery\Search\Cache;

/**
 * Indexes kept for the life of the PHP process: for a long-running server
 * (a worker, a daemon), where a request does not start from nothing.
 */
final class MemoryIndexCache implements IndexCache
{
    /** @var array<string, array<array-key, mixed>> */
    private array $entries = [];

    /**
     * @param int $size the most indexes kept; the oldest goes first
     */
    public function __construct(private readonly int $size = 32)
    {
        if ($size < 1) {
            throw new \InvalidArgumentException('The cache must hold at least one index.');
        }
    }

    public function get(string $key): ?array
    {
        if (!isset($this->entries[$key])) {
            return null;
        }
        // Moved to the end, so the indexes in use are the last to go.
        $index = $this->entries[$key];
        unset($this->entries[$key]);
        $this->entries[$key] = $index;

        return $index;
    }

    public function set(string $key, array $index): void
    {
        unset($this->entries[$key]);
        $this->entries[$key] = $index;
        while (count($this->entries) > $this->size) {
            unset($this->entries[array_key_first($this->entries)]);
        }
    }
}
