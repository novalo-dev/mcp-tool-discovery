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

/**
 * What the tests that run the docs share: the PHP blocks of one section of a
 * page, and a script run in its own process, so the classes a snippet
 * declares never meet the test run's.
 */
trait RunsSnippets
{
    /**
     * The code blocks of a page's section in one language, in order, the
     * section running to the next heading of the same level or higher.
     *
     * @return list<string>
     */
    private static function blocksOf(string $page, string $heading, string $language = 'php'): array
    {
        $text = (string) file_get_contents(\dirname(__DIR__, 2) . '/' . $page);
        $start = strpos($text, "\n" . $heading . "\n");
        self::assertNotFalse($start, $page . ' has no ' . $heading . ' section.');
        $level = strspn($heading, '#');
        preg_match('/\n#{1,' . $level . '} /', $text, $next, PREG_OFFSET_CAPTURE, $start + 1);
        $section = substr($text, $start, isset($next[0]) ? $next[0][1] - $start : null);
        preg_match_all('/^```' . preg_quote($language, '/') . '\n(.*?)^```/ms', (string) $section, $blocks);

        return $blocks[1];
    }

    /**
     * Runs a PHP script in a process of its own.
     *
     * @return array{int, string, string} the exit code, stdout and stderr
     */
    private static function runScript(string $script): array
    {
        $file = sys_get_temp_dir() . '/mcp-tool-discovery-docs-' . bin2hex(random_bytes(6)) . '.php';
        file_put_contents($file, $script);

        try {
            $process = proc_open([PHP_BINARY, $file], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            self::assertIsResource($process);
            $out = (string) stream_get_contents($pipes[1]);
            $err = (string) stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);

            return [proc_close($process), $out, $err];
        } finally {
            unlink($file);
        }
    }

    private static function autoload(): string
    {
        return 'require ' . var_export(\dirname(__DIR__, 2) . '/vendor/autoload.php', true) . ";\n";
    }
}
