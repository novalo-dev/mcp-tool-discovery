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

use Novalo\McpToolDiscovery\Discovery;
use Novalo\McpToolDiscovery\Mode\Direct\DirectMode;
use Novalo\McpToolDiscovery\Protocol\Revision;
use Novalo\McpToolDiscovery\Protocol\Shaper;
use Novalo\McpToolDiscovery\Tests\Fixtures\RecordingGate;
use Novalo\McpToolDiscovery\Tool\Tool;
use PHPUnit\Framework\TestCase;

final class DirectModeTest extends TestCase
{
    public function testTheListIsWhatAServerWithoutTheLibrarySendsLessTheHiddenTools(): void
    {
        $tools = RecordingGate::fixtureTools();
        $visible = $tools->filter(static fn(Tool $tool): bool => $tool->name !== 'acme_list_users');

        foreach (Revision::cases() as $revision) {
            self::assertSame(
                json_encode(Shaper::listResult($visible->all(), $revision, 60000)),
                json_encode((new Discovery(new DirectMode(), new RecordingGate(['acme_list_users'])))->listTools($tools, $revision, 60000)),
                $revision->value,
            );
        }
    }

    public function testAToolIsRunByItsOwnNameAfterTheGate(): void
    {
        $gate = new RecordingGate([], ['acme_deactivate_user']);
        $discovery = new Discovery(new DirectMode(), $gate);
        $tools = RecordingGate::fixtureTools();

        self::assertFalse($discovery->call('acme_get_file', ['file_id' => '1'], $tools)->isError);
        self::assertTrue($discovery->call('acme_deactivate_user', [], $tools)->isError);
        self::assertSame(['acme_get_file'], array_column($gate->called, 'name'));
        self::assertSame(['acme_get_file', 'acme_deactivate_user'], $gate->authorized);
    }
}
