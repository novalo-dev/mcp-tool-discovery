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
 * How the notes server on the official MCP PHP SDK tells a client of the
 * stateless revision that its list of tools changed. The SDK carries
 * notifications to `subscriptions/listen` streams through a bus, which every
 * process shares; docs/examples/sdk/http-server.php reads it for each
 * caller, and docs/examples/sdk/grant.php publishes on it when it changes
 * rights.
 */

namespace Example\Notes\Sdk;

use Mcp\Schema\JsonRpc\Notification;
use Mcp\Schema\Notification\ToolListChangedNotification;
use Mcp\Server\Subscription\NotificationBusInterface;
use Mcp\Server\Subscription\Psr6NotificationBus;
use Novalo\McpToolDiscovery\Discovery;
use Novalo\McpToolDiscovery\Protocol\Revision;
use Novalo\McpToolDiscovery\Tool\ToolSet;
use Symfony\Component\Cache\Adapter\FilesystemAdapter;

/**
 * The bus every process of the server shares. Under PHP-FPM the request that
 * changes rights and the one holding a stream open are different workers,
 * so the bus lives in files, not in memory.
 */
function notificationBus(string $data): NotificationBusInterface
{
    return new Psr6NotificationBus(new FilesystemAdapter('notes', 0, $data . '/notifications'));
}

/**
 * The bus as one caller's stream reads it. The bus is shared by every caller,
 * and a host that changes a right or a tool does not know whose list it
 * moves: it publishes that the tools changed, and each stream asks the
 * library whether its own caller's list did. A reader is then never told of
 * a tool only an admin may see, and a change in compact mode, where the list
 * stays the same, tells no one.
 */
final class CallerNotifications implements NotificationBusInterface
{
    private ?string $held = null;

    /**
     * @param \Closure(): Discovery $discovery the caller's Discovery, built again at each
     *        look with the rights the caller has then
     */
    public function __construct(
        private readonly NotificationBusInterface $bus,
        private readonly \Closure $discovery,
        private readonly ToolSet $tools,
        private readonly Revision $revision,
    ) {}

    public function publish(Notification $notification): void
    {
        $this->bus->publish($notification);
    }

    // The SDK asks for the cursor when a stream opens: the list the caller
    // may see at that moment is the one later changes are compared with.
    public function cursor(): int
    {
        $this->held = ($this->discovery)()->listFingerprint($this->tools, $this->revision);

        return $this->bus->cursor();
    }

    public function since(int $cursor): array
    {
        [$notifications, $next] = $this->bus->since($cursor);

        $kept = [];
        $tools = false;
        foreach ($notifications as $notification) {
            if ($notification instanceof ToolListChangedNotification) {
                $tools = true;

                continue;
            }
            $kept[] = $notification;
        }
        // Built only when the tools may have changed, not at each tick of
        // the stream, and the change is sent once however many were published.
        if ($tools) {
            $now = ($this->discovery)()->listFingerprint($this->tools, $this->revision);
            if ($now !== $this->held) {
                $this->held = $now;
                $kept[] = new ToolListChangedNotification();
            }
        }

        return [$kept, $next];
    }
}
