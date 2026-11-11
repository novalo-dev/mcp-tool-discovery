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
 * Indexes kept as PHP files, for a server where each request starts afresh.
 *
 * A PHP file that returns an array, rather than JSON or serialize(): OPcache
 * keeps it compiled in shared memory, so reading an index costs about as
 * little as having it in memory. A key never changes content, since it is the
 * hash of what was indexed, so a file is written once and never goes stale.
 *
 * The directory must be one only the server writes to: the files are run.
 */
final class FileIndexCache implements IndexCache
{
    public function __construct(private readonly string $directory) {}

    public function get(string $key): ?array
    {
        $file = $this->file($key);
        if (!is_file($file)) {
            return null;
        }
        $index = include $file;

        return is_array($index) ? $index : null;
    }

    public function set(string $key, array $index): void
    {
        if (!is_dir($this->directory) && !@mkdir($this->directory, 0o775, true) && !is_dir($this->directory)) {
            // A cache that cannot write only makes the next search slower.
            return;
        }
        $temporary = @tempnam($this->directory, 'index');
        if ($temporary === false) {
            return;
        }
        // Written aside then renamed, so a request never includes half a file.
        if (@file_put_contents($temporary, '<?php return ' . var_export($index, true) . ";\n") === false || !@rename($temporary, $this->file($key))) {
            @unlink($temporary);
        }
    }

    private function file(string $key): string
    {
        // The key is hashed again so it can never leave the directory.
        return rtrim($this->directory, '/\\') . '/' . hash('xxh128', $key) . '.php';
    }
}
