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
 */
final class FileLearningStore implements LearningStore
{
    /** No pair counts beyond this, so the file stays small however long it lives. */
    private const CEILING = 1000;

    /** @var array<string, array<string, int>>|null */
    private ?array $loaded = null;

    public function __construct(private readonly string $file) {}

    public function load(): array
    {
        if ($this->loaded === null) {
            $text = is_file($this->file) ? @file_get_contents($this->file) : false;
            $this->loaded = is_string($text) ? self::decode($text) : [];
        }

        return $this->loaded;
    }

    public function record(array $words, string $tool): void
    {
        $handle = @fopen($this->file, 'c+');
        if ($handle === false) {
            // Learning is a help, never a reason for a run to fail.
            return;
        }
        try {
            if (!flock($handle, LOCK_EX)) {
                return;
            }
            $text = stream_get_contents($handle);
            $counts = is_string($text) ? self::decode($text) : [];
            foreach (array_unique($words) as $word) {
                $counts[$word][$tool] = min(self::CEILING, ($counts[$word][$tool] ?? 0) + 1);
            }
            ftruncate($handle, 0);
            rewind($handle);
            fwrite($handle, json_encode($counts, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}');
            fflush($handle);
            flock($handle, LOCK_UN);
            $this->loaded = $counts;
        } finally {
            fclose($handle);
        }
    }

    /**
     * @return array<string, array<string, int>>
     */
    private static function decode(string $text): array
    {
        $data = json_decode($text, true);
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
