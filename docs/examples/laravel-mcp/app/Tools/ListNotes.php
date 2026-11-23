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
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
final class ListNotes extends Tool
{
    protected string $name = 'notes_list';

    protected string $title = 'List the notes';

    protected string $description = 'Lists every note, with its id and the start of its text.';

    public function handle(Notes $notes): Response
    {
        return Response::json($notes->starts());
    }
}
