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

use Example\Notes\LaravelMcp\McpServiceProvider;
use Example\Notes\LaravelMcp\NotesServer;
use Illuminate\Foundation\Application;
use Laravel\Mcp\Facades\Mcp;
use Laravel\Mcp\Server\McpServiceProvider as LaravelMcpServiceProvider;

// The whole application: two providers and one route. laravel/mcp's provider
// is named rather than discovered, so the example does not depend on the
// package manifest. The route is outside the web group on purpose: its
// session, cookies and CSRF check are for a browser.
return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(using: static function (): void {
        // laravel/mcp's own route: POST for messages, 405 for the rest. The
        // guard answers 401 to a request that names no one.
        Mcp::web('/mcp', NotesServer::class)->middleware('auth:mcp');
    })
    ->withProviders([LaravelMcpServiceProvider::class, McpServiceProvider::class], withBootstrapProviders: false)
    // The default aliases, auth among them.
    ->withMiddleware()
    ->withExceptions()
    ->create();
