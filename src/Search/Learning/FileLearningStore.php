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
 * What was learned, kept in a JSON file shared by every request.
 *
 * Locked while it is rewritten, so two runs at the same moment both count.
 * One file serves one server: two servers with different tools need two.
 *
 * Learning is a help, never a reason for a run to fail, so a file that cannot
 * be read or written stops nothing; the reason is handed to the host's
 * callback, if it gave one, so a store that learns nothing shows in its log.
 */
final class FileLearningStore implements LearningStore
{
    /** No count goes beyond this; Counts::LIMIT bounds how many counts the file holds. */
    private const CEILING = 1000;

    /** @var array<string, array<string, int>>|null */
    private ?array $loaded = null;

    /** @var (\Closure(string): void)|null */
    private readonly ?\Closure $failed;

    /**
     * @param string $file the JSON file, created at the first run learned from
     * @param (callable(string): void)|null $failed told why the file could not be read or written
     */
    public function __construct(private readonly string $file, ?callable $failed = null)
    {
        // Refused here rather than in a run: an empty path would never learn,
        // and a null byte makes fopen() throw after the tool has run.
        if ($file === '' || str_contains($file, "\0")) {
            throw new \InvalidArgumentException('The file of the learning store must be a path without null bytes.');
        }
        $this->failed = $failed === null ? null : \Closure::fromCallable($failed);
    }

    public function load(): array
    {
        if ($this->loaded === null) {
            $text = false;
            if (is_file($this->file)) {
                $text = @file_get_contents($this->file);
                if ($text === false) {
                    $this->fail('cannot read ' . $this->file);
                }
            }
            $this->loaded = is_string($text) ? $this->decode($text) : [];
        }

        return $this->loaded;
    }

    public function record(array $words, string $tool): void
    {
        $handle = @fopen($this->file, 'c+');
        if ($handle === false) {
            $this->fail('cannot open ' . $this->file);

            return;
        }
        try {
            if (!flock($handle, LOCK_EX)) {
                $this->fail('cannot lock ' . $this->file);

                return;
            }
            $text = stream_get_contents($handle);
            $counts = is_string($text) ? $this->decode($text) : [];
            $counts = Counts::add($counts, $words, $tool, self::CEILING);
            ftruncate($handle, 0);
            rewind($handle);
            $json = json_encode($counts, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $json = is_string($json) ? $json : '{}';
            if (fwrite($handle, $json) !== strlen($json) || !fflush($handle)) {
                $this->fail('cannot write ' . $this->file);
            }
            flock($handle, LOCK_UN);
            $this->loaded = $counts;
        } finally {
            fclose($handle);
        }
    }

    private function fail(string $reason): void
    {
        if ($this->failed !== null) {
            ($this->failed)('The learning store ' . $reason . '.');
        }
    }

    /**
     * @return array<string, array<string, int>>
     */
    private function decode(string $text): array
    {
        $data = json_decode($text, true);
        if (!is_array($data) && trim($text) !== '') {
            // Started again from nothing, since the run must not fail; the
            // host hears of it, as what was learned is lost at the next write.
            $this->fail('found no JSON object in ' . $this->file);
        }
        $counts = [];
        foreach (is_array($data) ? $data : [] as $word => $tools) {
            foreach (is_array($tools) ? $tools : [] as $tool => $count) {
                if (is_int($count) && $count > 0) {
                    $counts[(string) $word][(string) $tool] = $count;
                }
            }
        }

        return $counts;
    }
}
