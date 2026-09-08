<?php

declare(strict_types=1);

namespace App\Providers;

use App\Http\Middleware\EnsurePsceqApiKey;
use App\Services\PKI\TrustedXClientService;
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

        $this->app->singleton(TrustedXClientService::class, static function (): TrustedXClientService {
            return new TrustedXClientService(
                clientId: (string) config('trustedx.client_id'),
                baseUrl: (string) config('trustedx.base_url'),
                clientsLoggedAs: (string) config('trustedx.clients_logged_as'),
                adminsLoggedAs: (string) config('trustedx.admins_logged_as'),
                clientSecret: (string) config('trustedx.client_secret'),
            );
        });
    }

    public function boot(): void
    {
        JsonResource::withoutWrapping();
        $this->registerRateLimiters();
        $this->hideScrambleDecryptJson();
    }

    private function registerRateLimiters(): void
    {
        RateLimiter::for('api', $this->limitApi(...));
        RateLimiter::for('otp-send', $this->limitOtpSend(...));
        RateLimiter::for('otp-verify', $this->limitOtpVerify(...));
        RateLimiter::for('auth-login', $this->limitAuthLogin(...));
        RateLimiter::for('document-read', $this->limitDocumentRead(...));
        RateLimiter::for('document-verify', $this->limitDocumentVerify(...));
        RateLimiter::for('password-reset', $this->limitPasswordReset(...));
        RateLimiter::for('enrollment-suivi', $this->limitEnrollmentSuivi(...));
        RateLimiter::for('psceq', $this->limitPsceq(...));
    }

    private function limitApi(Request $request): Limit
    {
        return Limit::perMinute(60)->by($request->user()?->id ?: $request->ip());
    }

    private function limitOtpSend(Request $request): Limit
    {
        return Limit::perMinute(3)->by($this->otpIdentifier($request));
    }

    private function limitOtpVerify(Request $request): Limit
    {
        return Limit::perMinute(10)->by($this->otpIdentifier($request));
    }

    private function limitAuthLogin(Request $request): Limit
    {
        return Limit::perMinute(5)->by(strtolower((string) $request->input('email')).'|'.$request->ip());
    }

    private function limitDocumentRead(Request $request): Limit
    {
        return Limit::perMinute(10)->by($request->ip());
    }

    private function limitDocumentVerify(Request $request): Limit
    {
        // Étape 2 personne morale : route authentifiée, quota par client et non par IP —
        // plusieurs demandeurs peuvent partager une sortie NAT.
        return Limit::perMinute(10)->by((string) ($request->user()?->id ?: $request->ip()));
    }

    private function limitPasswordReset(Request $request): Limit
    {
        return Limit::perMinute(3)->by($request->ip());
    }

    private function limitEnrollmentSuivi(Request $request): Limit
    {
        return Limit::perMinute(10)->by(
            strtolower((string) $request->input('numero_suivi')).'|'.$request->ip()
        );
    }

    private function limitPsceq(Request $request): Limit
    {
        $prefix = $request->attributes->get(EnsurePsceqApiKey::KEY_PREFIX_ATTRIBUTE);
        $key = is_string($prefix) && $prefix !== '' ? $prefix : (string) $request->ip();

        return Limit::perMinute(60)->by($key);
    }

    private function otpIdentifier(Request $request): string
    {
        $identifier = $request->input('email')
            ?: $request->input('phonenumber')
            ?: $request->input('npi')
            ?: '';

        return $identifier.'|'.$request->ip();
    }

    private function hideScrambleDecryptJson(): void
    {
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
