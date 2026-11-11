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
 * Prints the conformance score of each revision and writes one shields.io
 * endpoint file per revision into build/badges/, which the conformance
 * workflow publishes on the badges branch for the README.
 */

require dirname(__DIR__) . '/vendor/autoload.php';

use Novalo\McpToolDiscovery\Protocol\Revision;
use Novalo\McpToolDiscovery\Tests\Conformance\Conformance;

$dir = dirname(__DIR__) . '/build/badges';
if (!is_dir($dir) && !mkdir($dir, 0o777, true) && !is_dir($dir)) {
    fwrite(STDERR, 'Cannot create ' . $dir . "\n");
    exit(1);
}
$failed = false;
echo "| Revision | Checks |\n|---|---|\n";
foreach (Revision::cases() as $revision) {
    $score = Conformance::run($revision);
    $failed = $failed || $score['passed'] !== $score['total'];
    printf("| %s | %d/%d |\n", $revision->value, $score['passed'], $score['total']);
    foreach ($score['failures'] as $failure) {
        fwrite(STDERR, '  ' . $revision->value . ' ' . $failure . "\n");
    }
    file_put_contents($dir . '/conformance-' . $revision->value . '.json', json_encode([
        'schemaVersion' => 1,
        'label' => $revision->value,
        'message' => sprintf('%d/%d', $score['passed'], $score['total']),
        'color' => $score['passed'] === $score['total'] ? 'brightgreen' : ($score['passed'] * 2 >= $score['total'] ? 'yellow' : 'red'),
    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . "\n");
}
exit($failed ? 1 : 0);
