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

namespace Example\Notes\Laravel;

use Example\Notes\Notes;
use Example\Notes\NotesTools;
use Illuminate\Auth\AuthManager;
use Illuminate\Auth\GenericUser;
use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Http\Request;
use Illuminate\Support\ServiceProvider;
use Novalo\McpToolDiscovery\Search\Cache\FileIndexCache;
use Novalo\McpToolDiscovery\Search\SearchSetup;
use Novalo\McpToolDiscovery\Tool\Tool;

use const Example\Notes\TOKENS;

/**
 * The wiring of the application: the shared parts of the library, the guard
 * that finds the caller, and the ability that decides who sees a tool.
 */
final class McpServiceProvider extends ServiceProvider
{
    /** The ability the gate asks for each tool. */
    public const SEE = 'see-mcp-tool';

    public function register(): void
    {
        // Singletons: what they hold is the same for every caller. The index
        // cache is keyed by the tools a caller may see, so one caller never
        // reads another's index. The gate and the Discovery are not bound:
        // they hold one caller, and Octane keeps the container from one
        // request to the next.
        $this->app->singleton(Notes::class, function (): Notes {
            $data = $this->data();
            if (!is_dir($data)) {
                mkdir($data, 0o700, true);
            }

            return new Notes($data . '/notes.json');
        });
        $this->app->singleton(NotesTools::class, fn(): NotesTools => new NotesTools($this->app->make(Notes::class)));
        $this->app->singleton(SearchSetup::class, fn(): SearchSetup => new SearchSetup(cache: new FileIndexCache($this->data() . '/index')));
    }

    public function boot(AuthManager $auth, Gate $gate): void
    {
        // The bearer token names a user before the controller runs, and the
        // controller never reads the header. No session: each request names
        // its own caller.
        $auth->viaRequest('mcp-token', static function (Request $request): ?Authenticatable {
            $role = TOKENS[$request->bearerToken() ?? ''] ?? null;

            return $role === null ? null : new GenericUser(['id' => $role]);
        });

        // Only an admin may delete, as in NotesGate; every other tool is for
        // anyone the guard let in. A request with no user is denied by
        // Laravel before this runs, since the user is not nullable.
        $gate->define(self::SEE, static fn(Authenticatable $user, Tool $tool): bool => $tool->name !== 'notes_delete' || $user->getAuthIdentifier() === 'admin');
    }

    private function data(): string
    {
        $data = $this->app->make(Repository::class)->get('mcp.data');

        return is_string($data) ? $data : throw new \UnexpectedValueException('mcp.data must name a directory.');
    }
}
