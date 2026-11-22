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
 * Gives a role of the notes server the rights of another, and says so on the
 * bus of docs/examples/sdk/http-server.php. It stands for the page of a real
 * server where an administrator changes a user's rights: the change is
 * written where every request reads it, then published, and each open
 * stream decides whether its own caller's list moved.
 *
 * Run it:  MCP_NOTES_GRANTS=grants.json php docs/examples/sdk/grant.php reader admin
 * Take it back: the same with the role alone.
 */

namespace Example\Notes\Sdk;

use Mcp\Schema\Notification\ToolListChangedNotification;

require __DIR__ . '/vendor/autoload.php';
require __DIR__ . '/notifications.php';

$file = getenv('MCP_NOTES_GRANTS');
$role = $argv[1] ?? '';
if (!is_string($file) || $file === '' || $role === '') {
    fwrite(STDERR, "Usage: MCP_NOTES_GRANTS=<file> php grant.php <role> [<role whose rights it gets>]\n");
    exit(1);
}

$grants = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;
$grants = is_array($grants) ? $grants : [];
if (isset($argv[2])) {
    $grants[$role] = $argv[2];
} else {
    unset($grants[$role]);
}
file_put_contents($file, json_encode((object) $grants, JSON_THROW_ON_ERROR), LOCK_EX);

$data = getenv('MCP_NOTES_DATA');
$data = is_string($data) && $data !== '' ? $data : dirname(__DIR__) . '/var/sdk';
// Published after the write, so a stream that reads the change already sees
// the new rights.
notificationBus($data)->publish(new ToolListChangedNotification());
