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
 * Prints the scores of the search benchmark and what each mode's list costs
 * on the same servers, and writes the shields.io endpoint file of the score
 * that counts, the requests in fields, into build/badges/.
 */

require dirname(__DIR__) . '/vendor/autoload.php';

use Novalo\McpToolDiscovery\Tests\Fixtures\Benchmark;
use Novalo\McpToolDiscovery\Tests\Fixtures\ListCost;

$labels = [
    'fields' => 'In fields, nothing learned',
    'sentence' => 'The same requests as one sentence',
    'learnedUnseen' => 'In fields, after learning from the other half',
    'learnedAgain' => 'In fields, asked a second time',
];
$scores = Benchmark::run();
echo "Test half, requests kept aside while the settings were chosen:\n";
foreach ($labels as $key => $label) {
    printf("  %-46s first %3d/%d   top 3 %3d/%d\n", $label, $scores[$key]['first'], $scores[$key]['queries'], $scores[$key]['top3'], $scores[$key]['queries']);
}
foreach ($scores['fields']['misses'] as $miss) {
    echo '  missed ', $miss, "\n";
}
echo "\nBytes of JSON on ", ListCost::REVISION, ": each list, and one compact search answer:\n";
printf("  %-10s %5s %8s %8s %8s %8s %8s\n", 'server', 'tools', 'all', 'compact', 'gateway', 'grouped', 'search');
foreach (ListCost::run() as $server => $row) {
    printf("  %-10s %5d %8d %8d %8d %8s %8d\n", $server, $row['tools'], $row['all'], $row['compact'], $row['gateway'], $row['grouped'] ?? '-', $row['search']);
}
$score = $scores['fields'];
$dir = dirname(__DIR__) . '/build/badges';
if (!is_dir($dir) && !mkdir($dir, 0o777, true) && !is_dir($dir)) {
    fwrite(STDERR, 'Cannot create ' . $dir . "\n");
    exit(1);
}
$share = (int) round(100 * $score['first'] / max(1, $score['queries']));
file_put_contents($dir . '/search.json', json_encode([
    'schemaVersion' => 1,
    'label' => 'right tool first',
    'message' => $share . '%',
    'color' => $share >= 85 ? 'brightgreen' : ($share >= 70 ? 'yellow' : 'red'),
], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . "\n");
