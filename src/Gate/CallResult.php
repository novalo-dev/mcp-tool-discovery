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
     * A string that is not valid UTF-8 has each broken sequence replaced with
     * U+FFFD, in the text and in the structured content alike. The data often
     * carries what a host's tool returned, cut by bytes or read from a file,
     * and an answer that cannot be encoded would reach the client as an
     * exception after the tool has already changed things.
     *
     * @param array<string, mixed> $data
     */
    public static function json(array $data, bool $isError = false): self
    {
        /** @var array<string, mixed> $data the keys stay strings: only their bytes are repaired */
        $data = self::validUtf8($data);
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

    /**
     * The value with every string, and every string key, made valid UTF-8.
     * Objects are left as they are: a host that gives one decides how it is
     * written.
     */
    private static function validUtf8(mixed $value): mixed
    {
        if (is_string($value)) {
            return self::validString($value);
        }
        if (!is_array($value)) {
            return $value;
        }
        $repaired = [];
        foreach ($value as $key => $item) {
            $repaired[is_string($key) ? self::validString($key) : $key] = self::validUtf8($item);
        }

        return $repaired;
    }

    private static function validString(string $value): string
    {
        if (preg_match('//u', $value) === 1) {
            return $value;
        }
        // json_encode() knows how to replace a broken sequence without
        // mbstring, which the library never requires.
        $repaired = json_decode((string) json_encode($value, JSON_INVALID_UTF8_SUBSTITUTE));

        return is_string($repaired) ? $repaired : '';
    }
}
