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

            return [
                Limit::perMinute((int) config('app.rate_limits.login', 5))->by($email.'|'.$request->ip()),
                Limit::perMinute((int) config('app.rate_limits.login_ip', 20))->by($request->ip()),
            ];
        });

        RateLimiter::for('api', function (Request $request) {
            return Limit::perMinute((int) config('app.rate_limits.api', 60))
                ->by((string) ($request->user()?->getAuthIdentifier() ?? $request->ip()));
        });

        RateLimiter::for('ai', fn (Request $request) => Limit::perMinute((int) config('app.rate_limits.ai', 15))->by((string) ($request->user()?->getAuthIdentifier() ?? $request->ip())));
        RateLimiter::for('identity-extract', fn (Request $request) => Limit::perMinute((int) config('app.rate_limits.identity_extract', 6))->by((string) ($request->user()?->getAuthIdentifier() ?? $request->ip())));
        RateLimiter::for('pdf', fn (Request $request) => Limit::perMinute((int) config('app.rate_limits.pdf', 10))->by((string) ($request->user()?->getAuthIdentifier() ?? $request->ip())));
    }
}
