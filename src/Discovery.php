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

namespace Novalo\McpToolDiscovery;

use Novalo\McpToolDiscovery\Exception\InvalidCursor;
use Novalo\McpToolDiscovery\Exception\InvalidTool;
use Novalo\McpToolDiscovery\Gate\CallResult;
use Novalo\McpToolDiscovery\Gate\ToolGate;
use Novalo\McpToolDiscovery\Gate\ToolRunner;
use Novalo\McpToolDiscovery\Mode\DiscoveryMode;
use Novalo\McpToolDiscovery\Mode\GuideHeader;
use Novalo\McpToolDiscovery\Protocol\Revision;
use Novalo\McpToolDiscovery\Protocol\Shaper;
use Novalo\McpToolDiscovery\Search\SearchSetup;
use Novalo\McpToolDiscovery\Tool\Tool;
use Novalo\McpToolDiscovery\Tool\ToolSet;

/**
 * What a host calls from its `tools/list` and `tools/call` handlers.
 *
 * One instance per request: it holds the gate of the current caller. The
 * visible set is computed here, once, before the mode sees anything, so no
 * mode can list, search or run a tool the gate hides.
 */
final class Discovery
{
    /**
     * @param SearchSetup $search the search settings, and the optional cache and
     *        learning store, for whichever mode is used
     */
    public function __construct(
        private readonly DiscoveryMode $mode,
        private readonly ToolGate $gate,
        private readonly SearchSetup $search = new SearchSetup(),
    ) {}

    /**
     * The `result` member of the `tools/list` answer.
     *
     * @param 'private'|'public' $cacheScope
     *
     * @return array<string, mixed>
     *
     * @throws InvalidTool when a host tool carries the name of one of the mode's own tools
     */
    public function listTools(ToolSet $tools, Revision $revision, int $ttlMs = 0, string $cacheScope = 'private'): array
    {
        return Shaper::listResult($this->mode->listTools($this->visible($tools)), $revision, $ttlMs, $cacheScope);
    }

    /**
     * One page of the `tools/list` answer, for a host that pages its list.
     *
     * Pass the `cursor` of the request as it came, null for the first page.
     * The answer carries a `nextCursor` while tools remain. The cursor names
     * the last tool of the page, not a position: a tool added or removed
     * elsewhere in the list between two pages neither skips nor repeats one.
     *
     * @param 'private'|'public' $cacheScope
     *
     * @return array<string, mixed>
     *
     * @throws InvalidCursor when the cursor was not given by this list, or
     *         names a tool it no longer holds; answer with error -32602
     * @throws InvalidTool when a host tool carries the name of one of the mode's own tools
     */
    public function listPage(ToolSet $tools, Revision $revision, ?string $cursor, int $pageSize, int $ttlMs = 0, string $cacheScope = 'private'): array
    {
        if ($pageSize < 1) {
            throw new \InvalidArgumentException(sprintf('A page holds at least one tool, %d given.', $pageSize));
        }
        $listed = $this->mode->listTools($this->visible($tools));
        $start = $cursor === null ? 0 : self::after($listed, $cursor);
        $page = array_slice($listed, $start, $pageSize);
        $last = end($page);
        $next = $last !== false && $start + $pageSize < count($listed) ? self::cursor($last) : null;

        return Shaper::listResult($page, $revision, $ttlMs, $cacheScope, $next);
    }

    /**
     * A short string that changes when, and only when, the whole list this
     * caller would be given changes, for a host that tells its clients with
     * `notifications/tools/list_changed`.
     *
     * Keep the one of the list a session was given, and compare it with the
     * one of the next request. It covers every page at once and leaves out
     * what is not the list (`nextCursor`, `ttlMs`, `cacheScope`), so a host
     * that pages its list or changes its cache times is not misled by them.
     * It is taken for one revision, because a client holds the list shaped
     * for its own: a change to a field its revision leaves out tells it
     * nothing.
     *
     * @throws InvalidTool when a host tool carries the name of one of the mode's own tools
     */
    public function listFingerprint(ToolSet $tools, Revision $revision): string
    {
        $shaped = array_map(static fn(Tool $tool): array => Shaper::tool($tool, $revision), $this->mode->listTools($this->visible($tools)));

        // serialize() rather than JSON: a host tool with bytes JSON cannot
        // encode still gets a fingerprint, and the mode's order is kept, as
        // the client sees it.
        return hash('sha256', serialize($shaped));
    }

    /**
     * The `result` member of the `tools/call` answer.
     *
     * A host tool called by its own name is still accepted in every mode: an
     * assistant holding a list from before the host switched modes keeps
     * working, and the call meets the same checks as through the mode.
     *
     * @param array<mixed> $arguments as json_decode(..., true) gives them;
     *        a list, which a JSON array decodes to, is refused
     *
     * @return array<string, mixed>
     *
     * @throws InvalidTool when a host tool carries the name of one of the mode's own tools
     */
    public function callTool(string $name, array $arguments, ToolSet $tools, Revision $revision): array
    {
        return Shaper::callResult($this->call($name, $arguments, $tools), $revision);
    }

    /**
     * The same call, before it is shaped, for hosts that build their own answer.
     *
     * @param array<mixed> $arguments as json_decode(..., true) gives them
     *
     * @throws InvalidTool when a host tool carries the name of one of the mode's own tools
     */
    public function call(string $name, array $arguments, ToolSet $tools): CallResult
    {
        if (!ToolRunner::isObject($arguments)) {
            // Before the mode as well as the gate: a host that checks
            // is_array() lets a JSON array through, and neither should have
            // to tell it from named arguments.
            return CallResult::error('arguments must be an object.');
        }
        $runner = new ToolRunner($this->visible($tools), $this->gate, $this->search);

        return $this->mode->owns($name) ? $this->mode->call($name, $arguments, $runner) : $runner->run($name, $arguments);
    }

    private static function cursor(Tool $tool): string
    {
        return rtrim(strtr(base64_encode($tool->name), '+/', '-_'), '=');
    }

    /**
     * The position that follows the tool a cursor names.
     *
     * Only the listed tools are compared, so a cursor naming a hidden tool is
     * refused exactly as one naming no tool at all: the error says nothing
     * about what another caller may see.
     *
     * @param list<Tool> $listed
     */
    private static function after(array $listed, string $cursor): int
    {
        foreach ($listed as $position => $tool) {
            if (self::cursor($tool) === $cursor) {
                return $position + 1;
            }
        }

        throw InvalidCursor::given();
    }

    /**
     * The block to put at the top of a guide (an MCP prompt) that names
     * tools, saying how the current mode reaches them; "" when the mode
     * lists them as themselves. Asked here rather than of the mode, so the
     * header is built from the tools the caller may see and never names
     * one the gate hides, whatever the host passes.
     *
     * @param iterable<string> $names the tool names the guide uses
     *
     * @throws InvalidTool when a host tool carries the name of one of the mode's own tools
     */
    public function guideHeader(iterable $names, ToolSet $tools): string
    {
        $visible = $this->visible($tools);

        return $this->mode instanceof GuideHeader ? $this->mode->guideHeader($names, $visible) : '';
    }

    private function visible(ToolSet $tools): ToolSet
    {
        foreach ($tools as $tool) {
            // A host tool under the name of one of the mode's own tools could
            // never be reached. Leaving it out would hide it from every caller
            // without a word, so the host learns of it on the first request.
            // Checked before the gate, so the answer is the same for every
            // caller and says nothing about what one of them may see.
            if ($this->mode->owns($tool->name)) {
                throw InvalidTool::because($tool->name, sprintf('the %s mode has a tool of its own under this name, so this one could never be called; give the mode a prefix or rename the tool.', $this->mode->name()));
            }
        }

        return $tools->filter(fn($tool): bool => $this->gate->visible($tool));
    }
}
