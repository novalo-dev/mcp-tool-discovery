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
 * The rules of the repository a linter does not know, checked on every file
 * git tracks:
 * - src/ names no host framework, so the library stays usable by any server;
 * - every PHP file carries the licence header;
 * - no long dash and no stock phrase, so the text reads as written by a person;
 * - no line that credits the work to a tool rather than to its authors, in a
 *   file or, with --commits <range>, in a commit message.
 * This file holds the patterns, so it is the one file not read.
 */

$root = dirname(__DIR__);
$files = [];

// --commits <range>: the messages of those commits, instead of the files.
$range = ($argv[1] ?? '') === '--commits' ? ($argv[2] ?? '') : null;

exec('git -C ' . escapeshellarg($root) . ' ls-files --cached --others --exclude-standard', $files, $status);
if ($status !== 0) {
    fwrite(STDERR, "git ls-files failed\n");
    exit(1);
}

$header = "/*\n * This file is part of novalo/mcp-tool-discovery.\n";
// Built from pieces so that this file does not match itself.
$credit = '/(' . implode('|', ['Co-Authored' . '-By', 'Generated (with|by) ', 'Claude', 'Anthropic', 'Chat' . 'GPT', 'Open' . 'AI', "\u{1F916}", 'written by (an )?AI', 'AI-generated']) . ')/i';
$stock = '/\b(' . implode('|', ['del' . 've', 'seam' . 'less(ly)?', 'lever' . 'age', 'cutting' . '-edge', 'game' . '-changer', 'robust' . 'ness', 'in con' . 'clusion', "it's worth " . 'noting']) . ')\b/i';
$framework = '/\b(' . implode('|', ['PrestaShop', 'somcp', 'Laravel', 'Illuminate', 'Symfony', 'WordPress', 'Drupal', 'Magento']) . ')\b/i';
$longDash = "\u{2014}";

$problems = [];
if ($range !== null) {
    $log = [];
    exec('git -C ' . escapeshellarg($root) . ' log --format=%H%x00%B%x01 ' . escapeshellarg($range), $log, $status);
    if ($status !== 0) {
        fwrite(STDERR, "git log failed\n");
        exit(1);
    }
    foreach (array_filter(explode("\x01", implode("\n", $log))) as $commit) {
        $parts = explode("\0", trim($commit), 2);
        $sha = $parts[0];
        $message = $parts[1] ?? '';
        if (preg_match($credit, $message) === 1) {
            $problems[] = substr($sha, 0, 7) . ': the message credits the work to a tool';
        }
        if (str_contains($message, $longDash)) {
            $problems[] = substr($sha, 0, 7) . ': long dash in the message';
        }
    }
    $files = [];
}
foreach ($files as $file) {
    if ($file === 'bin/check-hygiene.php' || !is_file($root . '/' . $file)) {
        continue;
    }
    $text = file_get_contents($root . '/' . $file);
    if ($text === false || str_contains($text, "\0")) {
        continue;
    }
    foreach (preg_split('/\R/', $text) ?: [] as $index => $line) {
        $at = $file . ':' . ($index + 1);
        if (str_contains($line, $longDash)) {
            $problems[] = $at . ': long dash, use a simple hyphen';
        }
        if (preg_match($credit, $line) === 1) {
            $problems[] = $at . ': credits the work to a tool';
        }
        if (preg_match($stock, $line, $match) === 1) {
            $problems[] = $at . ': stock phrase "' . $match[1] . '"';
        }
        if (str_starts_with($file, 'src/') && preg_match($framework, $line, $match) === 1) {
            $problems[] = $at . ': src/ names a host framework, "' . $match[1] . '"';
        }
    }
    if (str_ends_with($file, '.php') && !str_starts_with(substr($text, 0, 200), "<?php\n\n" . $header)) {
        $problems[] = $file . ': licence header missing, run composer cs-fix';
    }
}

foreach ($problems as $problem) {
    // An annotation on the run, when it runs in GitHub Actions.
    if (getenv('GITHUB_ACTIONS') === 'true') {
        echo '::error::', $problem, "\n";
    }
    echo $problem, "\n";
}
echo count($problems) === 0 ? "Hygiene: clean\n" : count($problems) . " problem(s)\n";
exit(count($problems) === 0 ? 0 : 1);
