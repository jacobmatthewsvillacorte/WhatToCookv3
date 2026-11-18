<?php

namespace App\Providers;

use App\Services\RecipeCatalog;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
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
        RateLimiter::for('auth', fn (Request $request) => Limit::perMinute(5)->by(strtolower((string) $request->input('email')).'|'.$request->ip()));
        RateLimiter::for('api', fn (Request $request) => Limit::perMinute(60)->by((string) ($request->user()?->id ?? $request->ip())));

        RecipeCatalog::registerInvalidation();

        if (app()->environment('production') && config('app.force_https')) {
            URL::forceScheme('https');
        }
    }
}
