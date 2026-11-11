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

namespace Novalo\McpToolDiscovery\Protocol;

use Novalo\McpToolDiscovery\Gate\CallResult;
use Novalo\McpToolDiscovery\Tool\Tool;

/**
 * Turns tools and results into the exact JSON shape of one revision.
 *
 * The host stays in charge of the JSON-RPC envelope: this only builds the
 * `result` member, so it fits any transport and any server framework.
 */
final class Shaper
{
    /**
     * The tool as the revision defines it, unknown fields left out.
     *
     * @return array<string, mixed>
     */
    public static function tool(Tool $tool, Revision $revision): array
    {
        $definition = $tool->toArray();
        $shaped = [];
        foreach ($revision->toolFields() as $field) {
            if (array_key_exists($field, $definition)) {
                $shaped[$field] = $definition[$field];
            }
        }
        // Before 2025-06-18 a tool had no title of its own, and the 2025-03-26
        // annotations held it: keep it there so the client still shows it.
        if (!in_array('title', $revision->toolFields(), true) && isset($definition['title'])) {
            if (in_array('annotations', $revision->toolFields(), true)) {
                $shaped['annotations'] = ['title' => $definition['title']] + $tool->annotations();
            }
        }
        foreach (['inputSchema', 'outputSchema'] as $field) {
            if (isset($shaped[$field]) && is_array($shaped[$field])) {
                $shaped[$field] = Json::schema($shaped[$field]);
            }
        }
        foreach (['annotations', '_meta'] as $field) {
            if (isset($shaped[$field]) && is_array($shaped[$field])) {
                $shaped[$field] = Json::object($shaped[$field]);
            }
        }

        return $shaped;
    }

    /**
     * The `result` of a `tools/list` response.
     *
     * The stateless revision requires the list to say how long it may be
     * cached. The defaults are the safe ones: no caching, and private to this
     * caller, since a list filtered by the caller's rights is never public.
     *
     * @param list<Tool> $tools
     * @param 'private'|'public' $cacheScope
     *
     * @return array<string, mixed>
     */
    public static function listResult(array $tools, Revision $revision, int $ttlMs = 0, string $cacheScope = 'private', ?string $nextCursor = null): array
    {
        $result = ['tools' => array_map(static fn(Tool $tool): array => self::tool($tool, $revision), $tools)];
        if ($nextCursor !== null) {
            $result['nextCursor'] = $nextCursor;
        }
        if ($revision->isStateless()) {
            $result['ttlMs'] = max(0, $ttlMs);
            $result['cacheScope'] = $cacheScope === 'public' ? 'public' : 'private';
            $result['resultType'] = 'complete';
        }

        return $result;
    }

    /**
     * The `result` of a `tools/call` response.
     *
     * @return array<string, mixed>
     */
    public static function callResult(CallResult $result, Revision $revision): array
    {
        $shaped = ['content' => $result->content];
        if ($result->isError) {
            $shaped['isError'] = true;
        }
        if ($result->structuredContent !== null && $revision->hasStructuredContent()) {
            $shaped['structuredContent'] = Json::object($result->structuredContent);
        }
        if ($revision->isStateless()) {
            $shaped['resultType'] = 'complete';
        }

        return $shaped;
    }
}
