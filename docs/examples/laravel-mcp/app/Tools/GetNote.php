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
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
final class GetNote extends Tool
{
    protected string $name = 'notes_get';

    protected string $title = 'Read a note';

    protected string $description = 'Returns the full text of one note.';

    public function schema(JsonSchema $schema): array
    {
        return ['id' => $schema->integer()->required()];
    }

    public function handle(Request $request, Notes $notes): Response
    {
        // Strict, as laravel/mcp checks its own parameters: "1" is not an id.
        $id = $request->validate(['id' => ['required', 'integer:strict']])['id'];
        $text = is_int($id) ? $notes->get($id) : null;

        return $text === null ? Response::error(sprintf('No note %d.', is_int($id) ? $id : 0)) : Response::text($text);
    }
}
