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
 * Keeps built search indexes between requests.
 *
 * Building the index is most of the cost of a search. The key is a hash of
 * the tools the caller may see and of the settings, so a caller with fewer
 * rights never reads an index built over tools hidden from them, and a change
 * to any tool gives a new key rather than a stale index.
 */
interface IndexCache
{
    /**
     * @return array<array-key, mixed>|null the saved index, or null when there is none
     */
    public function get(string $key): ?array;

    /**
     * @param array<string, mixed> $index
     */
    public function set(string $key, array $index): void;
}
