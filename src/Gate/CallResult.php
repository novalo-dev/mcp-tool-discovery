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

namespace Novalo\McpToolDiscovery\Gate;

/**
 * The answer to a `tools/call`, before it is shaped for a protocol revision.
 *
 * A refusal is a result with `isError`, not an exception: the specification
 * reports tool-level failures inside the result so the assistant reads them
 * and can try something else, while a protocol error ends the exchange.
 */
final class CallResult
{
    /**
     * @param list<array<string, mixed>> $content content blocks of the specification
     * @param array<string, mixed>|null $structuredContent
     */
    public function __construct(
        public readonly array $content,
        public readonly bool $isError = false,
        public readonly ?array $structuredContent = null,
    ) {}

    public static function text(string $text, bool $isError = false): self
    {
        return new self([['type' => 'text', 'text' => $text]], $isError);
    }

    public static function error(string $message): self
    {
        return self::text($message, true);
    }

    /**
     * A JSON answer, given both as text, which every revision reads, and as
     * structured content, which the revisions from 2025-06-18 add.
     *
     * @param array<string, mixed> $data
     */
    public static function json(array $data, bool $isError = false): self
    {
        $text = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        return new self([['type' => 'text', 'text' => $text]], $isError, $data);
    }

    /**
     * Builds a result from the array a host's tool returned.
     *
     * @param array<string, mixed> $result a CallToolResult of the specification
     */
    public static function fromArray(array $result): self
    {
        $content = [];
        foreach (is_array($result['content'] ?? null) ? $result['content'] : [] as $block) {
            if (is_array($block)) {
                /** @var array<string, mixed> $block a content block is a JSON object */
                $content[] = $block;
            }
        }
        /** @var array<string, mixed>|null $structured structured content is a JSON object */
        $structured = is_array($result['structuredContent'] ?? null) ? $result['structuredContent'] : null;

        return new self($content, ($result['isError'] ?? false) === true, $structured);
    }

    public function withContent(string $text): self
    {
        return new self([...$this->content, ['type' => 'text', 'text' => $text]], $this->isError, $this->structuredContent);
    }
}
