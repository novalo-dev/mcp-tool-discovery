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

namespace Novalo\McpToolDiscovery\Search;

/**
 * What a caller's action word means for the verb a tool name starts with.
 *
 * MCP tools are conventionally named verb_object: list_orders, set_status,
 * delete_page. A query says "change the status" or "turn off the page", and
 * no text match links "change" to "set". This table does, for plain English
 * action words only: it holds no word of any business domain, which belongs
 * in the host's synonyms.
 */
final class VerbIntent
{
    /**
     * @var array<string, list<string>> action word => verbs a tool name may start with
     */
    private const VERBS = [
        'activate' => ['set', 'enable', 'update'],
        'add' => ['add', 'create'],
        'adjust' => ['set', 'update'],
        'alter' => ['update', 'set'],
        'audit' => ['audit', 'check'],
        'browse' => ['list', 'search'],
        'cancel' => ['cancel', 'delete'],
        'change' => ['set', 'update'],
        'check' => ['check', 'audit', 'get'],
        'clear' => ['clear', 'delete', 'purge'],
        'count' => ['count', 'list', 'get'],
        'create' => ['create', 'add'],
        'deactivate' => ['set', 'disable', 'update'],
        'delete' => ['delete', 'remove'],
        'describe' => ['get', 'describe'],
        'detail' => ['get'],
        'details' => ['get'],
        'disable' => ['set', 'disable', 'update'],
        'download' => ['get', 'download', 'export'],
        'drop' => ['delete', 'remove'],
        'edit' => ['update', 'set'],
        'enable' => ['set', 'enable', 'update'],
        'erase' => ['delete', 'remove'],
        'export' => ['export', 'get'],
        'fetch' => ['get', 'list'],
        'find' => ['search', 'get', 'list', 'find'],
        'generate' => ['generate', 'create'],
        'get' => ['get'],
        'import' => ['import', 'create'],
        'insert' => ['add', 'create'],
        'install' => ['install'],
        'list' => ['list'],
        'look' => ['search', 'get', 'find'],
        'make' => ['create', 'generate'],
        'mark' => ['set', 'update'],
        'modify' => ['update', 'set'],
        'new' => ['create', 'add'],
        'open' => ['get'],
        'purge' => ['clear', 'purge', 'delete'],
        'read' => ['get', 'list', 'read'],
        'register' => ['create', 'add'],
        'remove' => ['delete', 'remove', 'cancel'],
        'rename' => ['update', 'set'],
        'reset' => ['reset', 'clear', 'set'],
        'resend' => ['resend', 'send'],
        'restore' => ['restore', 'set'],
        'retrieve' => ['get', 'list'],
        'search' => ['search', 'find', 'list'],
        'see' => ['list', 'get'],
        'send' => ['send', 'resend'],
        'set' => ['set', 'update'],
        'show' => ['list', 'get'],
        'switch' => ['set', 'update'],
        'toggle' => ['set', 'update'],
        'turn' => ['set', 'update'],
        'uninstall' => ['uninstall', 'delete'],
        'update' => ['update', 'set'],
        'upload' => ['upload', 'create', 'add'],
        'view' => ['get', 'list'],
    ];

    /**
     * Whether a word is an action word or a verb tool names start with.
     */
    public static function isVerb(string $word): bool
    {
        if (isset(self::VERBS[$word])) {
            return true;
        }
        foreach (self::VERBS as $verbs) {
            if (in_array($word, $verbs, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The verbs a tool name may start with to answer these words.
     *
     * @param list<string> $words words of the query, as the Analyzer gives them
     *
     * @return array<string, true>
     */
    public static function verbsFor(array $words): array
    {
        $verbs = [];
        foreach ($words as $word) {
            foreach (self::VERBS[$word] ?? [] as $verb) {
                $verbs[$verb] = true;
            }
        }

        return $verbs;
    }
}
