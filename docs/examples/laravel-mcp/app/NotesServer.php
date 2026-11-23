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

namespace Example\Notes\LaravelMcp;

use Example\Notes\LaravelMcp\Tools\AddNote;
use Example\Notes\LaravelMcp\Tools\DeleteNote;
use Example\Notes\LaravelMcp\Tools\GetNote;
use Example\Notes\LaravelMcp\Tools\ListNotes;
use Laravel\Mcp\Server;

/**
 * A laravel/mcp server as its documentation writes one, with two lines
 * more: tools/list and tools/call are answered by the library. The tools,
 * the transports, initialize and the other methods stay laravel/mcp's.
 */
final class NotesServer extends Server
{
    protected string $name = 'Notes';

    protected string $version = '1.0.0';

    protected string $instructions = 'Notes kept for the team. Search the tools with notes_search_tools, then run the one found.';

    protected array $tools = [
        ListNotes::class,
        GetNote::class,
        AddNote::class,
        DeleteNote::class,
    ];

    protected function boot(): void
    {
        $this->addMethod('tools/list', ListToolsMethod::class);
        $this->addMethod('tools/call', CallToolMethod::class);
    }
}
