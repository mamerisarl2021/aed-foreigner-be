<?php

namespace App\Providers;

use App\Services\Regula\HttpRegulaService;
use App\Services\Regula\MockRegulaService;
use App\Services\Regula\RegulaService;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

final class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Real Regula client by default; the mock is opt-in (REGULA_MOCK=true, local dev only).
        $this->app->bind(RegulaService::class, function () {
            return config('services.regula.mock')
                ? new MockRegulaService
                : new HttpRegulaService;
        });
    }

    public function boot(): void
    {
        RateLimiter::for('api', function (Request $request) {
            return Limit::perMinute(60)->by($request->user()?->id ?: $request->ip());
        });

        RateLimiter::for('otp-send', function (Request $request) {
            $identifier = $request->input('email')
                ?: $request->input('phonenumber')
                ?: $request->input('npi')
                ?: '';

            return Limit::perMinute(3)->by($identifier.'|'.$request->ip());
        });

        RateLimiter::for('otp-verify', function (Request $request) {
            $identifier = $request->input('email')
                ?: $request->input('phonenumber')
                ?: $request->input('npi')
                ?: '';

            return Limit::perMinute(10)->by($identifier.'|'.$request->ip());
        });

        RateLimiter::for('auth-login', function (Request $request) {
            return Limit::perMinute(5)->by(strtolower((string) $request->input('email')).'|'.$request->ip());
        });

        RateLimiter::for('password-reset', function (Request $request) {
            return Limit::perMinute(3)->by($request->ip());
        });
    }
}
