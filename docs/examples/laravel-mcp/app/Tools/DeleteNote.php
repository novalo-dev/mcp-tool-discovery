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
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[IsDestructive]
final class DeleteNote extends Tool
{
    protected string $name = 'notes_delete';

    protected string $title = 'Delete a note';

    protected string $description = 'Deletes one note for good.';

    public function schema(JsonSchema $schema): array
    {
        return ['id' => $schema->integer()->required()];
    }

    /**
     * laravel/mcp's own way to hide a tool from a caller: the library sees
     * only what ServerContext::tools() keeps, so a reader can neither find
     * this tool nor run it through a runner.
     */
    public function shouldRegister(Request $request): bool
    {
        return $request->user('mcp')?->getAuthIdentifier() === 'admin';
    }

    public function handle(Request $request, Notes $notes): Response
    {
        $id = $request->validate(['id' => ['required', 'integer:strict']])['id'];

        return is_int($id) && $notes->delete($id) ? Response::text(sprintf('Note %d deleted.', $id)) : Response::error(sprintf('No note %d.', is_int($id) ? $id : 0));
    }
}
