<?php

declare(strict_types=1);

namespace App\Providers;

use App\Services\Regula\HttpRegulaService;
use App\Services\Regula\MockRegulaService;
use App\Services\Regula\RegulaService;
use Dedoc\Scramble\Scramble;
use Dedoc\Scramble\Support\Generator\OpenApi;
use Dedoc\Scramble\Support\Generator\Response as OpenApiResponse;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

final class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Real Regula client by default; the mock is opt-in (REGULA_MOCK=true, local dev only).
        $this->app->bind(RegulaService::class, function ($app) {
            return config('services.regula.mock')
                ? $app->make(MockRegulaService::class)
                : $app->make(HttpRegulaService::class);
        });
    }

    public function boot(): void
    {
        JsonResource::withoutWrapping();

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

        RateLimiter::for('document-read', function (Request $request) {
            return Limit::perMinute(10)->by($request->ip());
        });

        // Étape 2 personne morale : route authentifiée, donc quota par client et non par IP —
        // plusieurs demandeurs peuvent partager une sortie NAT.
        RateLimiter::for('document-verify', function (Request $request) {
            return Limit::perMinute(10)->by((string) ($request->user()?->id ?: $request->ip()));
        });

        RateLimiter::for('password-reset', function (Request $request) {
            return Limit::perMinute(3)->by($request->ip());
        });

        RateLimiter::for('enrollment-suivi', function (Request $request) {
            return Limit::perMinute(10)->by(
                strtolower((string) $request->input('numero_suivi')).'|'.$request->ip()
            );
        });

        // Scramble merges inferred JSON content with #[Response] binary for decrypt; drop the noise.
        Scramble::afterOpenApiGenerated(function (OpenApi $openApi): void {
            foreach ($openApi->paths as $path) {
                if (! str_ends_with($path->path, '/decrypt/token/file/{filename}')) {
                    continue;
                }

                $operation = $path->operations['get'] ?? null;
                if ($operation === null || $operation->responses === null) {
                    continue;
                }

                foreach ($operation->responses as $response) {
                    if (! $response instanceof OpenApiResponse || (int) $response->code !== 200) {
                        continue;
                    }
                    unset($response->content['application/json']);
                }
            }
        });
    }
}
