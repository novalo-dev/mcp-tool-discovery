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

use Novalo\McpToolDiscovery\Exception\UnsupportedRevision;

/**
 * The revisions of the MCP specification the library shapes answers for.
 *
 * Each revision only knows some fields of a tool and of a call result. A
 * client validating strictly against its own revision may reject a field it
 * does not know, so answers are cut to what the negotiated revision defines.
 * The field lists below are read from the official schema.json of each
 * revision, and the conformance suite checks them against those files.
 */
enum Revision: string
{
    case V2024_11_05 = '2024-11-05';
    case V2025_03_26 = '2025-03-26';
    case V2025_06_18 = '2025-06-18';
    case V2025_11_25 = '2025-11-25';
    case V2026_07_28 = '2026-07-28';

    public static function latest(): self
    {
        return self::V2026_07_28;
    }

    /**
     * The latest revision that opens with an `initialize` handshake.
     *
     * Read from the cases rather than named, so that a host falling back to it
     * moves on by itself when the library learns a newer handshake revision,
     * instead of keeping the one its author copied.
     */
    public static function latestWithHandshake(): self
    {
        $latest = self::V2024_11_05;
        foreach (self::cases() as $revision) {
            if (!$revision->isStateless()) {
                $latest = $revision;
            }
        }

        return $latest;
    }

    /**
     * The revision to answer an `initialize` request with.
     *
     * The specification asks a server for the client's own revision when it
     * supports it, and otherwise for another it supports, preferably its
     * latest. Which revisions can be answered is what this library shapes,
     * so the rule lives here once instead of in every host. The stateless
     * revision is never the answer: it has no handshake, so a client asking
     * for it in `initialize` gets the latest revision that has one.
     *
     * It takes whatever the client sent: `protocolVersion` comes from JSON and
     * may be missing or not a string, which the rule answers the same way as
     * an unknown revision.
     */
    public static function negotiate(mixed $requested): self
    {
        $revision = is_string($requested) ? self::tryFrom($requested) : null;

        return $revision === null || $revision->isStateless() ? self::latestWithHandshake() : $revision;
    }

    /**
     * @throws UnsupportedRevision
     */
    public static function fromString(string $revision): self
    {
        return self::tryFrom($revision) ?? throw UnsupportedRevision::named($revision);
    }

    /**
     * The fields of a Tool object this revision defines.
     *
     * @return list<string>
     */
    public function toolFields(): array
    {
        return match ($this) {
            self::V2024_11_05 => ['name', 'description', 'inputSchema'],
            self::V2025_03_26 => ['name', 'description', 'inputSchema', 'annotations'],
            self::V2025_06_18 => ['name', 'title', 'description', 'inputSchema', 'outputSchema', 'annotations', '_meta'],
            self::V2025_11_25 => ['name', 'title', 'description', 'inputSchema', 'outputSchema', 'annotations', 'icons', 'execution', '_meta'],
            self::V2026_07_28 => ['name', 'title', 'description', 'inputSchema', 'outputSchema', 'annotations', 'icons', '_meta'],
        };
    }

    /**
     * Whether a call result may carry `structuredContent`.
     */
    public function hasStructuredContent(): bool
    {
        return $this !== self::V2024_11_05 && $this !== self::V2025_03_26;
    }

    /**
     * Whether results must say `resultType`, and lists say how long they may
     * be cached (`ttlMs`, `cacheScope`): the stateless revision of 2026-07-28.
     */
    public function isStateless(): bool
    {
        return $this === self::V2026_07_28;
    }
}
