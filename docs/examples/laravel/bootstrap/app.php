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

use Example\Notes\Laravel\McpController;
use Example\Notes\Laravel\McpServiceProvider;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Route;

// The whole application: one provider and one route. The route is outside
// the web group on purpose: its session, cookies and CSRF check are for a
// browser, and an MCP client sends none of them.
return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(using: static function (): void {
        // Every method reaches the controller, which answers 405 itself
        // for those the transport does not take.
        Route::any('/mcp', McpController::class);
    })
    ->withProviders([McpServiceProvider::class], withBootstrapProviders: false)
    ->withExceptions()
    ->create();
