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

namespace Novalo\McpToolDiscovery\Tests\Unit;

use Novalo\McpToolDiscovery\Protocol\Revision;
use PHPUnit\Framework\TestCase;

final class RevisionTest extends TestCase
{
    public function testAClientGetsItsOwnRevisionWhenTheLibraryShapesIt(): void
    {
        foreach (Revision::cases() as $revision) {
            if (!$revision->isStateless()) {
                self::assertSame($revision, Revision::negotiate($revision->value));
            }
        }
    }

    public function testAnUnknownRevisionGetsTheLatestWithAHandshake(): void
    {
        self::assertSame(Revision::latestWithHandshake(), Revision::negotiate('2099-01-01'));
        self::assertSame(Revision::latestWithHandshake(), Revision::negotiate(''));
    }

    public function testTheStatelessRevisionIsNeverTheAnswerToAHandshake(): void
    {
        self::assertTrue(Revision::latest()->isStateless());
        self::assertSame(Revision::latestWithHandshake(), Revision::negotiate(Revision::latest()->value));
    }

    public function testWhatAClientSentThatIsNotAStringIsReadAsUnknown(): void
    {
        foreach ([null, 20251125, ['2025-11-25'], true] as $sent) {
            self::assertSame(Revision::latestWithHandshake(), Revision::negotiate($sent));
        }
    }

    public function testTheLatestWithAHandshakeIsTheNewestCaseThatHasOne(): void
    {
        $withHandshake = array_values(array_filter(Revision::cases(), static fn(Revision $revision): bool => !$revision->isStateless()));

        self::assertSame(end($withHandshake), Revision::latestWithHandshake());
        self::assertSame(Revision::V2025_11_25, Revision::latestWithHandshake());
    }
}
