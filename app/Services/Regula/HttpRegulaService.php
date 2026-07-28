<?php

declare(strict_types=1);

namespace App\Services\Regula;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Real Regula document-analysis client. Configured via config('services.regula').
 * Falls back to a KO status when the service is unreachable or misconfigured,
 * so KYC verification fails closed instead of silently passing.
 */
final class HttpRegulaService implements RegulaService
{
    /**
     * @param  array<string, mixed>  $files  Local paths keyed by document slot (selfie, recto, verso)
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function analyzeIdentity(array $files, array $data): array
    {
        $baseUrl = (string) config('services.regula.url');

        if ($baseUrl === '') {
            Log::error('Regula service URL is not configured (REGULA_URL). KYC analysis fails closed.');

            return ['status' => 'KO', 'details' => ['error' => 'regula_not_configured']];
        }

        try {
            $request = Http::timeout((int) config('services.regula.timeout', 30));

            $apiKey = (string) config('services.regula.api_key');
            if ($apiKey !== '') {
                $request = $request->withToken($apiKey);
            }

            foreach ($files as $slot => $path) {
                if (is_string($path) && is_file($path)) {
                    $request = $request->attach($slot, file_get_contents($path) ?: '', basename($path));
                }
            }

            $response = $request->post(rtrim($baseUrl, '/').'/api/v1/identity/analyze', $data);

            if (! $response->successful()) {
                Log::warning('Regula analysis returned a non-success response.', ['http_status' => $response->status()]);

                return ['status' => 'KO', 'details' => ['error' => 'regula_http_'.$response->status()]];
            }

            $payload = $response->json();

            return [
                'status' => (string) ($payload['status'] ?? 'KO'),
                'risk_score' => $payload['risk_score'] ?? null,
                'details' => $payload['details'] ?? $payload,
            ];
        } catch (\Throwable $e) {
            Log::error('Regula analysis failed: '.$e->getMessage());

            return ['status' => 'KO', 'details' => ['error' => 'regula_unreachable']];
        }
    }
}
