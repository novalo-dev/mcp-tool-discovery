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

/*
 * Reads a search log written as the stdio example writes it (one JSON line
 * per search and per run, see NotesSearchLog in notes.php) and prints what
 * is worth acting on: the words no tool uses with the tool that ran after
 * them, the runs of a tool the search did not put first, and the searches
 * that found nothing. Each is a lead for the host's synonyms, for a title to
 * write again, or for a request to add to the benchmark.
 *
 * Run it:   php docs/examples/search-log-report.php search.log
 *
 * It needs nothing but PHP and reads the file line by line. It keeps the
 * tools found by each search until the end, so a log of millions of searches
 * is better read one day at a time.
 */

namespace Example\Notes;

/**
 * @param resource $input
 *
 * @return list<string>
 */
function searchLogReport($input, int $top = 20): array
{
    /** @var array<string, list<string>> $found the tools found, by search_id */
    $found = [];
    /** @var array<string, array<string, string>> $fieldsOf */
    $fieldsOf = [];
    /** @var array<string, list<string>> $unmatchedOf */
    $unmatchedOf = [];
    /** @var array<string, int> $words */
    $words = [];
    /** @var array<string, array<string, int>> $ranAfter tools run after a word, by word */
    $ranAfter = [];
    /** @var array<string, int> $empty */
    $empty = [];
    /** @var array<string, int> $misses */
    $misses = [];
    $searches = $runs = $tied = $first = $failed = $unreadable = 0;

    while (($line = fgets($input)) !== false) {
        if (trim($line) === '') {
            continue;
        }
        $entry = json_decode($line, true);
        // A line cut by a full disk or a crash is counted, not fatal: the
        // rest of the log still says something.
        if (!\is_array($entry)) {
            ++$unreadable;
            continue;
        }
        $id = \is_string($entry['search'] ?? null) ? $entry['search'] : null;

        if (\is_array($entry['found'] ?? null)) {
            ++$searches;
            $tools = array_values(array_filter($entry['found'], 'is_string'));
            $fields = array_filter(\is_array($entry['fields'] ?? null) ? $entry['fields'] : [], 'is_string');
            $unmatched = array_values(array_unique(array_filter(\is_array($entry['unmatched'] ?? null) ? $entry['unmatched'] : [], 'is_string')));
            foreach ($unmatched as $word) {
                $words[$word] = ($words[$word] ?? 0) + 1;
            }
            if ($tools === []) {
                $key = describeFields($fields);
                $empty[$key] = ($empty[$key] ?? 0) + 1;
            }
            if ($id !== null) {
                $found[$id] = $tools;
                $fieldsOf[$id] = $fields;
                $unmatchedOf[$id] = $unmatched;
            }
            continue;
        }

        if (!\is_string($entry['ran'] ?? null)) {
            ++$unreadable;
            continue;
        }
        ++$runs;
        $tool = $entry['ran'];
        if (($entry['succeeded'] ?? null) !== true) {
            // A failed run says more about the arguments than about the
            // search: it is counted, and leaves the ranks alone.
            ++$failed;
            continue;
        }
        if ($id === null || !isset($found[$id])) {
            continue;
        }
        ++$tied;
        $rank = array_search($tool, $found[$id], true);
        if ($rank === 0) {
            ++$first;
        } else {
            $key = $tool . "\t" . ($rank === false ? 'not found' : ordinal($rank + 1)) . "\t" . describeFields($fieldsOf[$id]);
            $misses[$key] = ($misses[$key] ?? 0) + 1;
        }
        foreach ($unmatchedOf[$id] as $word) {
            $ranAfter[$word][$tool] = ($ranAfter[$word][$tool] ?? 0) + 1;
        }
    }

    $report = [
        \sprintf('%d searches, %d runs (%d failed), %d unreadable lines', $searches, $runs, $failed, $unreadable),
        \sprintf('%d of %d successful runs after a search ran the first tool found', $first, $tied),
        '',
        'Words no tool uses, and what ran after them',
    ];
    foreach (ranked($words, $top) as $word => $count) {
        $after = [];
        foreach (ranked($ranAfter[$word] ?? [], 3) as $tool => $times) {
            $after[] = \sprintf('%s (%d)', $tool, $times);
        }
        $report[] = \sprintf('  %-20s %4d  %s', $word, $count, $after === [] ? '-' : implode(', ', $after));
    }
    $report = [...$report, ...more($words, $top), '', 'Runs of a tool the search did not put first'];
    foreach (ranked($misses, $top) as $key => $count) {
        [$tool, $rank, $fields] = explode("\t", (string) $key, 3);
        $report[] = \sprintf('  %-20s %-10s %4d  %s', $tool, $rank, $count, $fields);
    }
    $report = [...$report, ...more($misses, $top), '', 'Searches that found nothing'];
    foreach (ranked($empty, $top) as $fields => $count) {
        $report[] = \sprintf('  %4d  %s', $count, $fields);
    }

    return [...$report, ...more($empty, $top)];
}

/**
 * @param array<array-key, string> $fields
 */
function describeFields(array $fields): string
{
    $parts = [];
    foreach ($fields as $name => $value) {
        // A request holds what a person typed: one line each, so a newline
        // in it cannot pass for a line of the report, and every other
        // control or direction character shown as "?", since the report is
        // read in a terminal that would act on it rather than print it.
        $line = preg_replace(['/\s+/u', '/[\p{Cc}\x{061C}\x{200E}\x{200F}\x{202A}-\x{202E}\x{2066}-\x{2069}]/u'], [' ', '?'], $value);
        $parts[] = $name . ': ' . ($line ?? '?');
    }

    return implode(' | ', $parts);
}

function ordinal(int $n): string
{
    $suffix = ($n % 100 >= 11 && $n % 100 <= 13) ? 'th' : (['th', 'st', 'nd', 'rd'][$n % 10] ?? 'th');

    return $n . $suffix;
}

/**
 * The most frequent first, then by name, so two runs on the same log print
 * the same report.
 *
 * @param array<array-key, int> $counts
 *
 * @return array<array-key, int>
 */
function ranked(array $counts, int $top): array
{
    uksort($counts, static fn(int|string $a, int|string $b): int => [$counts[$b], (string) $a] <=> [$counts[$a], (string) $b]);

    return \array_slice($counts, 0, $top, true);
}

/**
 * @param array<array-key, int> $counts
 *
 * @return list<string>
 */
function more(array $counts, int $top): array
{
    if ($counts === []) {
        return ['  (none)'];
    }

    return \count($counts) > $top ? [\sprintf('  and %d more', \count($counts) - $top)] : [];
}

$file = $argv[1] ?? null;
$input = \is_string($file) ? @fopen($file, 'r') : false;
if ($input === false) {
    fwrite(STDERR, "Usage: php search-log-report.php <search log>\n");
    exit(1);
}
echo implode("\n", searchLogReport($input)), "\n";
