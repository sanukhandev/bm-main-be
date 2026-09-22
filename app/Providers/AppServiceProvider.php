<?php

namespace App\Providers;

use App\Support\Branch\BranchContext;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->scoped(BranchContext::class, fn () => new BranchContext);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        RateLimiter::for('login', function (Request $request) {
            $email = Str::lower(trim((string) $request->input('email')));

            return Limit::perMinute((int) env('LOGIN_RATE_LIMIT', 5))
                ->by($email.'|'.$request->ip());
        });

        RateLimiter::for('api', function (Request $request) {
            return Limit::perMinute((int) env('API_RATE_LIMIT', 60))
                ->by((string) ($request->user()?->getAuthIdentifier() ?? $request->ip()));
        });
    }
}
