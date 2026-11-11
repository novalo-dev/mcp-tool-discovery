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
 * Prints the score of the search benchmark and writes its shields.io endpoint
 * file into build/badges/.
 */

require dirname(__DIR__) . '/vendor/autoload.php';

use Novalo\McpToolDiscovery\Tests\Fixtures\Benchmark;

$score = Benchmark::run();
printf("Right tool first: %d/%d\nRight tool in the top 3: %d/%d\n", $score['first'], $score['queries'], $score['top3'], $score['queries']);
foreach ($score['misses'] as $miss) {
    echo '  missed ', $miss, "\n";
}
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
