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

namespace Novalo\McpToolDiscovery\Tests\Fixtures;

use Novalo\McpToolDiscovery\Mode\Grouped\GroupedMode;
use Novalo\McpToolDiscovery\Tool\Tool;

/**
 * The groups of tests/Fixtures/tools.json, as a host would declare them.
 * The deployments, alerts and the rest are left out on purpose, so every
 * test also meets tools listed as themselves.
 */
final class Groups
{
    public const TITLES = [
        'files' => 'Files and folders',
        'calendar' => 'Calendar',
        'tickets' => 'Support tickets',
        'users' => 'Users',
    ];

    private const WORDS = [
        'file' => 'files',
        'files' => 'files',
        'folder' => 'files',
        'event' => 'calendar',
        'events' => 'calendar',
        'slots' => 'calendar',
        'ticket' => 'tickets',
        'tickets' => 'tickets',
        'user' => 'users',
        'users' => 'users',
    ];

    public static function of(Tool $tool): string
    {
        foreach (explode('_', $tool->name) as $word) {
            if (isset(self::WORDS[$word])) {
                return self::WORDS[$word];
            }
        }

        return '';
    }

    public static function mode(string $prefix = ''): GroupedMode
    {
        return new GroupedMode([self::class, 'of'], self::TITLES, $prefix);
    }
}
