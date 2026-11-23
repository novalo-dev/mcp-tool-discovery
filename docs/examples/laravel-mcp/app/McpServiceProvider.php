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

use Example\Notes\Notes;
use Illuminate\Auth\AuthManager;
use Illuminate\Auth\GenericUser;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Http\Request;
use Illuminate\Support\ServiceProvider;
use Novalo\McpToolDiscovery\Search\Cache\FileIndexCache;
use Novalo\McpToolDiscovery\Search\SearchSetup;

use const Example\Notes\TOKENS;

final class McpServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Singletons: what they hold is the same for every caller. The index
        // cache is keyed by the tools a caller may see, so one caller never
        // reads another's index. The Discovery and its gate are built by the
        // methods for each message: they hold one caller's tools.
        $this->app->singleton(Notes::class, function (): Notes {
            $data = $this->data();
            if (!is_dir($data)) {
                mkdir($data, 0o700, true);
            }

            return new Notes($data . '/notes.json');
        });
        $this->app->singleton(SearchSetup::class, fn(): SearchSetup => new SearchSetup(cache: new FileIndexCache($this->data() . '/index')));
    }

    public function boot(AuthManager $auth): void
    {
        // The bearer token names a user, read by DeleteNote::shouldRegister()
        // and by the auth:mcp middleware of the route. No session: each
        // request names its own caller.
        $auth->viaRequest('mcp-token', static function (Request $request): ?Authenticatable {
            $role = TOKENS[$request->bearerToken() ?? ''] ?? null;

            return $role === null ? null : new GenericUser(['id' => $role]);
        });
    }

    private function data(): string
    {
        $data = $this->app->make(Repository::class)->get('mcp.data');

        return is_string($data) ? $data : throw new \UnexpectedValueException('mcp.data must name a directory.');
    }
}
