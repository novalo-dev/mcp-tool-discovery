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
 *
 * A cache that cannot write only makes the next search slower, so a failure
 * never stops a request; it is handed to the host's callback, if it gave one,
 * so a directory left unwritable shows in its log rather than as a server
 * that quietly builds its index on every request.
 */
final class FileIndexCache implements IndexCache
{
    /** @var (\Closure(string): void)|null */
    private readonly ?\Closure $failed;

    /**
     * @param string $directory where the indexes are kept, created if it is missing
     * @param (callable(string): void)|null $failed told why an index could not be kept
     */
    public function __construct(private readonly string $directory, ?callable $failed = null)
    {
        // Refused here rather than at the first search: an empty path would
        // never keep anything, and a null byte makes PHP throw in mid-request.
        if ($directory === '' || str_contains($directory, "\0")) {
            throw new \InvalidArgumentException('The directory of the index cache must be a path without null bytes.');
        }
        $this->failed = $failed === null ? null : \Closure::fromCallable($failed);
    }

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
            $this->fail('cannot create the directory ' . $this->directory);

            return;
        }
        // tempnam() falls back to the system's temporary directory when this
        // one is not writable; the rename below would then cross into it.
        $temporary = is_writable($this->directory) ? @tempnam($this->directory, 'index') : false;
        if ($temporary === false) {
            $this->fail('cannot write in the directory ' . $this->directory);

            return;
        }
        $file = $this->file($key);
        // Written aside then renamed, so a request never includes half a file.
        if (@file_put_contents($temporary, '<?php return ' . var_export($index, true) . ";\n") === false || !@rename($temporary, $file)) {
            @unlink($temporary);
            $this->fail('cannot write ' . $file);
        }
    }

    private function fail(string $reason): void
    {
        if ($this->failed !== null) {
            ($this->failed)('The index cache ' . $reason . '.');
        }
    }

    private function file(string $key): string
    {
        // The key is hashed again so it can never leave the directory.
        return rtrim($this->directory, '/\\') . '/' . hash('xxh128', $key) . '.php';
    }
}
