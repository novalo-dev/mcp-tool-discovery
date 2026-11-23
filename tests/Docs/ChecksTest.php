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

namespace Novalo\McpToolDiscovery\Tests\Docs;

use PHPUnit\Framework\TestCase;

/**
 * Keeps the checks CONTRIBUTING describes true to the CI that runs them.
 * Each example job runs a suite the main test job leaves out, and is or is
 * not part of CI passed: a contributor reads CONTRIBUTING to know what must
 * be green, so a job added to the workflow and not to the table, or moved
 * into CI passed while the table says otherwise, misleads them. Both have
 * happened once.
 */
final class ChecksTest extends TestCase
{
    public function testEverySuiteTheTestJobLeavesOutHasAJobOfItsOwn(): void
    {
        $excluded = self::excludedSuites();
        $run = array_keys(self::suiteJobs());
        sort($excluded);
        sort($run);

        self::assertNotSame([], $excluded);
        self::assertSame($excluded, $run);
    }

    public function testEveryExampleJobIsDescribedAsItIsRequired(): void
    {
        $rows = self::contributingRows();
        $required = self::requiredJobs();
        $wrong = [];
        foreach (self::suiteJobs() as $suite => $job) {
            $row = null;
            foreach ($rows as $candidate) {
                if (str_contains($candidate, '`' . $suite . '` test suite')) {
                    $row = $candidate;
                }
            }
            if (null === $row) {
                $wrong[] = $suite . ': no row of CONTRIBUTING names its test suite';

                continue;
            }
            $described = str_contains($row, 'part of **CI passed**') && !str_contains($row, 'not part of **CI passed**');
            if ($described !== \in_array($job, $required, true)) {
                $wrong[] = $suite . ': CONTRIBUTING says ' . ($described ? 'part of' : 'not part of') . ' CI passed, the workflow says otherwise';
            }
        }

        self::assertSame([], $wrong);
    }

    public function testTheTestRowNamesEverySuiteRunApart(): void
    {
        $row = '';
        foreach (self::contributingRows() as $candidate) {
            if (str_contains($candidate, '| Tests on PHP')) {
                $row = $candidate;
            }
        }
        $missing = array_values(array_filter(
            self::excludedSuites(),
            static fn(string $suite): bool => !str_contains($row, '`' . $suite . '`'),
        ));

        self::assertNotSame('', $row);
        self::assertSame([], $missing);
    }

    public function testCiPassedReadsTheResultOfEveryJobItWaitsFor(): void
    {
        $block = self::jobs()['passed'] ?? '';
        $read = preg_match_all('/needs\.([a-z0-9-]+)\.result \}\}" = success/', $block, $matches) > 0 ? $matches[1] : [];
        $needs = self::requiredJobs();
        sort($read);
        sort($needs);

        // A job in needs whose result is not read would let a failure pass.
        self::assertNotSame([], $needs);
        self::assertSame($needs, $read);
    }

    /**
     * The jobs of the CI workflow, each with its block of lines.
     *
     * @return array<string, string>
     */
    private static function jobs(): array
    {
        $text = (string) file_get_contents(\dirname(__DIR__, 2) . '/.github/workflows/ci.yml');
        $jobs = substr($text, (int) strpos($text, "\njobs:\n"));
        $parts = preg_split('/^  ([a-z0-9-]+):\n/m', $jobs, -1, PREG_SPLIT_DELIM_CAPTURE);
        $parts = false === $parts ? [] : $parts;
        $found = [];
        for ($i = 1; $i + 1 < \count($parts); $i += 2) {
            $found[$parts[$i]] = $parts[$i + 1];
        }

        return $found;
    }

    /**
     * @return list<string>
     */
    private static function excludedSuites(): array
    {
        if (1 !== preg_match('/--exclude-testsuite ([a-z0-9,-]+)/', self::jobs()['tests'] ?? '', $match)) {
            return [];
        }

        return explode(',', $match[1]);
    }

    /**
     * Each suite run by a job of its own, with that job.
     *
     * @return array<string, string>
     */
    private static function suiteJobs(): array
    {
        $found = [];
        foreach (self::jobs() as $job => $block) {
            if (1 === preg_match('/--testsuite ([a-z0-9-]+)/', $block, $match)) {
                $found[$match[1]] = $job;
            }
        }

        return $found;
    }

    /**
     * @return list<string>
     */
    private static function requiredJobs(): array
    {
        if (1 !== preg_match('/needs: \[([a-z0-9, -]+)\]/', self::jobs()['passed'] ?? '', $match)) {
            return [];
        }

        return array_map('trim', explode(',', $match[1]));
    }

    /**
     * @return list<string>
     */
    private static function contributingRows(): array
    {
        $text = (string) file_get_contents(\dirname(__DIR__, 2) . '/CONTRIBUTING.md');

        return preg_match_all('/^\| CI \|.*$/m', $text, $matches) > 0 ? $matches[0] : [];
    }
}
