<?php

namespace App\Providers;

use Illuminate\Pagination\Paginator;
use Illuminate\Support\Carbon;
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

        Vite::prefetch(concurrency: 3);
    }
}
