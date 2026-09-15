<?php

namespace App\Providers;

use App\Enums\PortalSection;
use App\Models\User;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        // Laravel ships Tailwind-flavoured pagination markup; we removed
        // Tailwind, so point the paginator at our own view.
        Paginator::defaultView('vendor.pagination.breakfast');
        Paginator::defaultSimpleView('vendor.pagination.breakfast');

        // Dates render in Spanish ("hace 3 días", "ago 2026").
        Carbon::setLocale(config('app.locale'));

        /*
         * Portal access, for @can in Blade and $this->authorize() in
         * controllers. Both delegate to the User helpers so there is still
         * exactly one place that decides — see User::accessTo().
         *
         *   @can('read', $section)     is the section open to them at all
         *   @can('write', $section)    may they change what is in it
         */
        Gate::define('read', fn (User $user, PortalSection $section) => $user->canRead($section));
        Gate::define('write', fn (User $user, PortalSection $section) => $user->canWrite($section));

        Vite::prefetch(concurrency: 3);

        /*
         * Cache-busting, since the built filenames no longer carry a hash.
         *
         * The root .htaccess sets `ExpiresByType text/css A31536000` — one
         * YEAR — so a stable admin.css would sit in a returning admin's
         * browser until 2027. The hash used to solve that by changing the
         * name; the cost was a new file per build and a manifest.json that had
         * to be uploaded after it, in that order, or every page came up
         * unstyled. See the note in vite.config.js.
         *
         * So the name holds still and the URL moves instead: ?v=<mtime>. A
         * browser keys its cache on the whole URL, query included, so a
         * changed stamp is a different resource and gets refetched. The stamp
         * needs no bumping by hand because uploading a file is what changes
         * its mtime — the deploy IS the cache bust.
         *
         * Falls back to no query when the file is missing rather than throwing
         * (fail closed on the *stamp*, not on the page): a stale asset is
         * survivable, a 500 on every screen is not. @vite complains about a
         * genuinely absent entry on its own, and says something useful.
         */
        Vite::createAssetPathsUsing(function (string $path, ?bool $secure = null) {
            $stamp = is_file($file = public_path($path)) ? filemtime($file) : false;

            return asset($path, $secure).($stamp ? '?v='.$stamp : '');
        });
    }
}
