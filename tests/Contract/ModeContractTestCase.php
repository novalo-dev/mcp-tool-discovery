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

namespace Novalo\McpToolDiscovery\Tests\Contract;

use Novalo\McpToolDiscovery\Discovery;
use Novalo\McpToolDiscovery\Mode\DiscoveryMode;
use Novalo\McpToolDiscovery\Protocol\Revision;
use Novalo\McpToolDiscovery\Tests\Fixtures\RecordingGate;
use Novalo\McpToolDiscovery\Tool\Tool;
use Novalo\McpToolDiscovery\Tool\ToolSet;
use PHPUnit\Framework\TestCase;

/**
 * The rules every mode keeps, whoever wrote it.
 *
 * A new mode extends this class and answers three questions: the mode, how it
 * calls a given host tool, and a few calls of its own tools that reach
 * nothing in particular (a search, a listing). Every test below then runs
 * against it. A mode that fails one of them is not merged, however useful.
 */
abstract class ModeContractTestCase extends TestCase
{
    /** A read the gate hides: the caller must never learn it exists. */
    protected const HIDDEN = 'acme_list_users';

    /** A change the gate shows but refuses at call time. */
    protected const REFUSED = 'acme_deactivate_user';

    /** A read the caller may run. */
    protected const READ = 'acme_get_file';

    /** A change the caller may run. */
    protected const WRITE = 'acme_set_ticket_status';

    abstract protected function mode(): DiscoveryMode;

    /**
     * How an assistant runs this host tool through the mode: the name of one
     * of the mode's own tools and its arguments.
     *
     * @return array{0: string, 1: array<string, mixed>}
     */
    abstract protected function callTo(Tool $tool): array;

    /**
     * Calls of the mode's own tools that run no host tool, such as searches
     * and listings, to check what they reveal. Leave out calls that name the
     * hidden tool: echoing back a name the caller typed reveals nothing, and
     * the mode's own tests check it is answered like a name that does not exist.
     *
     * @return list<array{0: string, 1: array<string, mixed>}>
     */
    abstract protected function probes(): array;

    private function gate(): RecordingGate
    {
        return new RecordingGate([self::HIDDEN], [self::REFUSED]);
    }

    private function tools(): ToolSet
    {
        return RecordingGate::fixtureTools();
    }

    private function tool(string $name): Tool
    {
        $tool = $this->tools()->get($name);
        self::assertNotNull($tool, $name . ' must exist in tests/Fixtures/tools.json');

        return $tool;
    }

    public function testTheListHoldsOnlyTheModesToolsAndVisibleTools(): void
    {
        $mode = $this->mode();
        $listed = (new Discovery($mode, $this->gate()))->listTools($this->tools(), Revision::latest());
        $visible = $this->tools()->filter(static fn(Tool $tool): bool => $tool->name !== self::HIDDEN);

        foreach ($listed['tools'] as $tool) {
            self::assertIsArray($tool);
            $name = $tool['name'];
            self::assertIsString($name);
            self::assertTrue($mode->owns($name) || $visible->has($name), $name . ' is neither the mode\'s nor visible');
        }
    }

    public function testAHiddenToolIsNeverNamedByTheModesOwnTools(): void
    {
        $discovery = new Discovery($this->mode(), $this->gate());
        $outputs = [json_encode($discovery->listTools($this->tools(), Revision::latest()))];
        foreach ($this->probes() as [$name, $arguments]) {
            $outputs[] = json_encode($discovery->callTool($name, $arguments, $this->tools(), Revision::latest()));
        }

        foreach ($outputs as $output) {
            self::assertIsString($output);
            self::assertStringNotContainsString(self::HIDDEN, $output);
        }
    }

    public function testAHiddenToolNeverRuns(): void
    {
        $gate = $this->gate();
        [$name, $arguments] = $this->callTo($this->tool(self::HIDDEN));
        $result = (new Discovery($this->mode(), $gate))->call($name, $arguments, $this->tools());

        self::assertTrue($result->isError);
        self::assertSame([], $gate->called);
        self::assertNotContains(self::HIDDEN, $gate->authorized, 'not even asked about: it does not exist for this caller');
    }

    public function testARefusedToolNeverRuns(): void
    {
        $gate = $this->gate();
        [$name, $arguments] = $this->callTo($this->tool(self::REFUSED));
        $result = (new Discovery($this->mode(), $gate))->call($name, $arguments, $this->tools());

        self::assertTrue($result->isError);
        self::assertContains(self::REFUSED, $gate->authorized);
        self::assertSame([], $gate->called);
    }

    public function testAnAllowedToolRunsOnceAfterAuthorization(): void
    {
        foreach ([self::READ, self::WRITE] as $target) {
            $gate = $this->gate();
            [$name, $arguments] = $this->callTo($this->tool($target));
            $result = (new Discovery($this->mode(), $gate))->call($name, $arguments, $this->tools());

            self::assertFalse($result->isError, $target);
            self::assertSame([$target], array_column($gate->called, 'name'));
            self::assertContains($target, $gate->authorized);
        }
    }

    public function testAToolThatRunsAChangeIsMarkedAsOne(): void
    {
        $mode = $this->mode();
        [$name] = $this->callTo($this->tool(self::WRITE));
        if (!$mode->owns($name)) {
            // The change is called by its own name, with its own annotations.
            $this->addToAssertionCount(1);

            return;
        }
        foreach ($mode->listTools($this->tools()) as $tool) {
            if ($tool->name === $name) {
                self::assertFalse($tool->readsOnly(), $name . ' runs changes but says it only reads');
                self::assertTrue(($tool->annotations()['destructiveHint'] ?? true) !== false, $name . ' runs changes: it must not say it is harmless');

                return;
            }
        }
        self::fail($name . ' runs changes but is not in the list');
    }

    public function testTheModesOwnToolsAreValidForEveryRevision(): void
    {
        foreach (Revision::cases() as $revision) {
            $listed = (new Discovery($this->mode(), $this->gate()))->listTools($this->tools(), $revision);
            self::assertIsArray($listed['tools']);
            foreach ($listed['tools'] as $tool) {
                self::assertIsArray($tool);
                self::assertSame([], array_diff(array_keys($tool), $revision->toolFields()), $revision->value);
            }
        }
    }
}
