<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // A technician's trip link: 60 requests a minute per LINK, not per IP --
        // a crew on one store's Wi-Fi shares an IP, and a guessed token should
        // be limited on its own.
        RateLimiter::for('trip-link', fn (Request $request) => Limit::perMinute(60)
            ->by('trip:'.hash('sha256', (string) $request->route('token')).'|'.$request->ip()));
    }
}
