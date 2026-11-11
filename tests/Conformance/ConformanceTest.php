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

namespace Novalo\McpToolDiscovery\Tests\Conformance;

use Novalo\McpToolDiscovery\Protocol\Revision;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ConformanceTest extends TestCase
{
    /**
     * @return iterable<string, array{Revision}>
     */
    public static function revisions(): iterable
    {
        foreach (Revision::cases() as $revision) {
            yield $revision->value => [$revision];
        }
    }

    #[DataProvider('revisions')]
    public function testEveryAnswerMatchesTheOfficialSchema(Revision $revision): void
    {
        if (!is_file(Conformance::schemaPath($revision))) {
            self::markTestSkipped('Run php bin/fetch-schemas.php to download the official schemas.');
        }
        $score = Conformance::run($revision);

        self::assertSame($score['total'], $score['passed'], implode("\n", $score['failures']));
    }
}
