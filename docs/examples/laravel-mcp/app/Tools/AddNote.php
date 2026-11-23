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

namespace Example\Notes\LaravelMcp\Tools;

use Example\Notes\Notes;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

final class AddNote extends Tool
{
    protected string $name = 'notes_add';

    protected string $title = 'Add a note';

    protected string $description = 'Adds a note and returns its id.';

    public function schema(JsonSchema $schema): array
    {
        return ['text' => $schema->string()->required()];
    }

    public function handle(Request $request, Notes $notes): Response
    {
        $text = $request->validate(['text' => ['required', 'string', 'max:10000']])['text'];

        return is_string($text) ? Response::json(['id' => $notes->add($text)]) : Response::error('text must be a string.');
    }
}
