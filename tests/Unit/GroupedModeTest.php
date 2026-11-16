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
use Novalo\McpToolDiscovery\Gate\CallResult;
use Novalo\McpToolDiscovery\Mode\Grouped\GroupedMode;
use Novalo\McpToolDiscovery\Tests\Fixtures\Groups;
use Novalo\McpToolDiscovery\Tests\Fixtures\RecordingGate;
use Novalo\McpToolDiscovery\Tool\Tool;
use PHPUnit\Framework\TestCase;

final class GroupedModeTest extends TestCase
{
    /**
     * @param list<string> $hidden
     *
     * @return array<string, Tool>
     */
    private static function listed(array $hidden = ['acme_list_users'], string $prefix = ''): array
    {
        $gate = new RecordingGate($hidden);
        $visible = RecordingGate::fixtureTools()->filter(static fn(Tool $tool): bool => $gate->visible($tool));
        $listed = [];
        foreach (Groups::mode($prefix)->listTools($visible) as $tool) {
            $listed[$tool->name] = $tool;
        }

        return $listed;
    }

    /**
     * @param array<string, mixed> $arguments
     */
    private static function call(string $name, array $arguments, ?RecordingGate $gate = null): CallResult
    {
        return (new Discovery(Groups::mode(), $gate ?? new RecordingGate(['acme_list_users'], ['acme_deactivate_user'])))
            ->call($name, $arguments, RecordingGate::fixtureTools());
    }

    /**
     * @return array<string, mixed>
     */
    private static function answer(CallResult $result): array
    {
        self::assertIsArray($result->structuredContent);

        return $result->structuredContent;
    }

    public function testEachGroupIsAReadToolAndAChangeToolAndTheRestIsListedAsItself(): void
    {
        $listed = self::listed();

        foreach (array_keys(Groups::TITLES) as $group) {
            self::assertArrayHasKey($group, $listed);
            self::assertTrue($listed[$group]->readsOnly());
            self::assertArrayHasKey($group . GroupedMode::CHANGE_SUFFIX, $listed);
            self::assertFalse($listed[$group . GroupedMode::CHANGE_SUFFIX]->readsOnly());
        }
        // Tools outside the declared groups stay reachable by their own name.
        self::assertArrayHasKey('acme_deploy_app', $listed);
        self::assertArrayNotHasKey('acme_get_file', $listed);
        self::assertCount(8 + 50 - 28, $listed);
    }

    public function testTheReadToolListsEveryActionOfItsGroupAndOnlyVisibleOnes(): void
    {
        $users = self::listed()['users'];

        self::assertStringContainsString('- acme_get_user: ', $users->description());
        self::assertStringContainsString('Changes, run with users_change:', $users->description());
        self::assertStringContainsString('- acme_deactivate_user: ', $users->description());
        self::assertStringNotContainsString('acme_list_users', (string) json_encode($users->toArray()));
        self::assertSame(['acme_get_user', 'acme_search_users'], $users->inputSchema()['properties']['action']['enum'] ?? null);
    }

    public function testAGroupWithNothingVisibleIsLeftOut(): void
    {
        $listed = self::listed(['acme_create_event', 'acme_update_event', 'acme_cancel_event']);

        self::assertArrayHasKey('calendar', $listed);
        self::assertArrayNotHasKey('calendar_change', $listed, 'no change left to run');

        $files = ['acme_list_files', 'acme_get_file', 'acme_search_files', 'acme_upload_file', 'acme_rename_file', 'acme_move_file', 'acme_delete_file', 'acme_share_file', 'acme_create_folder'];
        $listed = self::listed($files);
        self::assertArrayNotHasKey('files', $listed);
        self::assertArrayNotHasKey('files_change', $listed);
    }

    public function testAGroupOfChangesOnlyKeepsItsReadToolToDescribeThem(): void
    {
        $listed = self::listed(['acme_list_tickets', 'acme_get_ticket']);

        self::assertArrayHasKey('tickets', $listed);
        self::assertArrayNotHasKey('action', $listed['tickets']->inputSchema()['properties'] ?? []);
        self::assertArrayHasKey('describe', $listed['tickets']->inputSchema()['properties'] ?? []);
    }

    public function testAPrefixGoesBeforeEveryGroupName(): void
    {
        $listed = self::listed(prefix: 'acme_');

        self::assertArrayHasKey('acme_files', $listed);
        self::assertArrayHasKey('acme_files_change', $listed);
        self::assertStringContainsString('run with acme_files_change', $listed['acme_files']->description());
    }

    public function testActionsRunThroughTheirGroupAndKind(): void
    {
        $gate = new RecordingGate();
        self::assertFalse(self::call('files', ['action' => 'acme_get_file', 'arguments' => ['file_id' => '1']], $gate)->isError);
        self::assertFalse(self::call('tickets_change', ['action' => 'acme_set_ticket_status', 'arguments' => []], $gate)->isError);
        self::assertSame([['name' => 'acme_get_file', 'arguments' => ['file_id' => '1']], ['name' => 'acme_set_ticket_status', 'arguments' => []]], $gate->called);
    }

    public function testAnActionSentToTheWrongToolIsRefusedBeforeTheGate(): void
    {
        $cases = [
            ['files', 'acme_delete_file', 'files_change'],
            ['files_change', 'acme_get_file', 'files'],
            ['users', 'acme_get_file', 'files'],
            ['users_change', 'acme_set_ticket_status', 'tickets_change'],
        ];
        foreach ($cases as [$tool, $action, $right]) {
            $gate = new RecordingGate();
            $result = self::call($tool, ['action' => $action], $gate);

            self::assertTrue($result->isError, $tool . ' ' . $action);
            self::assertStringContainsString('run it with ' . $right . '.', (string) json_encode($result->content));
            self::assertSame([], $gate->authorized);
        }

        $result = self::call('files', ['action' => 'acme_deploy_app']);
        self::assertStringContainsString('call it by its own name', (string) json_encode($result->content));
    }

    public function testAHiddenActionReadsLikeOneThatDoesNotExist(): void
    {
        $hidden = self::call('users', ['action' => 'acme_list_users']);
        $missing = self::call('users', ['action' => 'acme_list_nothing']);

        self::assertTrue($hidden->isError);
        self::assertSame(
            str_replace('acme_list_nothing', 'X', (string) json_encode($missing->content)),
            str_replace('acme_list_users', 'X', (string) json_encode($hidden->content)),
        );
    }

    public function testARefusedActionNeverRunsAndGetsItsParameters(): void
    {
        $gate = new RecordingGate([], ['acme_deactivate_user']);
        $result = self::call('users_change', ['action' => 'acme_deactivate_user'], $gate);

        self::assertTrue($result->isError);
        self::assertSame([], $gate->called);
        self::assertStringContainsString('Parameters of acme_deactivate_user', (string) json_encode($result->content));
    }

    public function testDescribeGivesTheSchemasOfTheGroupAndNamesNothingElse(): void
    {
        $gate = new RecordingGate(['acme_list_users']);
        $answer = self::answer(self::call('users', ['describe' => ['acme_deactivate_user', 'acme_list_users', 'acme_get_file', 'acme_get_user']], $gate));

        self::assertSame(['acme_deactivate_user', 'acme_get_user'], array_column($answer['tools'], 'name'));
        self::assertSame(['users_change', 'users'], array_column($answer['tools'], 'run_with'));
        self::assertSame(['acme_list_users', 'acme_get_file'], $answer['unknown']);
        self::assertSame([], $gate->authorized, 'describing runs nothing');
    }

    public function testMalformedCallsAreAnsweredAsErrors(): void
    {
        foreach ([['files', []], ['files', ['action' => 3]], ['files', ['action' => 'acme_get_file', 'arguments' => 'x']], ['files', ['describe' => 'acme_get_file']], ['files', ['describe' => []]], ['files_change', ['describe' => ['acme_get_file']]]] as [$tool, $arguments]) {
            self::assertTrue(self::call($tool, $arguments)->isError, $tool . ' ' . json_encode($arguments));
        }
    }

    public function testAGroupThatMakesNoValidNameIsRefusedAtBoot(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new GroupedMode([Groups::class, 'of'], ['my files' => 'Files']);
    }

    public function testAGroupThatClashesWithAChangeToolIsRefusedAtBoot(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new GroupedMode([Groups::class, 'of'], ['files' => 'Files', 'files_change' => 'Other']);
    }
}
