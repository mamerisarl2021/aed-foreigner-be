<?php

declare(strict_types=1);

namespace App\Services\Regula;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Thin client for Regula Face Web API (not exposed as AED public routes).
 *
 * ImageSource: DOCUMENT_PRINTED=1, LIVE=3, EXTERNAL=5.
 *
 * @see https://dev.regulaforensics.com/FaceSDK-web-openapi/
 */
final class FaceApiClient
{
    public const IMAGE_DOCUMENT_PRINTED = 1;

    public const IMAGE_LIVE = 3;

    public const IMAGE_EXTERNAL = 5;

    public function healthz(): bool
    {
        $base = $this->baseUrl();
        if ($base === '') {
            return false;
        }

        try {
            return $this->http()->get($base.'/api/healthz')->successful();
        } catch (\Throwable $e) {
            Log::warning('Face API healthz failed: '.$e->getMessage());

            return false;
        }
    }

    /**
     * 1:1 face compare. `$images` items: path (file) or raw base64 string under key `data`, plus `type`.
     *
     * @param  list<array{type: int, path?: string, data?: string, index?: int}>  $images
     * @return array{ok: bool, payload: array<string, mixed>|null, error: string|null}
     */
    public function match(array $images): array
    {
        $base = $this->baseUrl();
        if ($base === '') {
            return ['ok' => false, 'payload' => null, 'error' => 'face_url_not_configured'];
        }

        $payloadImages = [];
        foreach ($images as $i => $image) {
            $data = $image['data'] ?? null;
            if (($data === null || $data === '') && isset($image['path']) && is_file($image['path'])) {
                $bytes = file_get_contents($image['path']);
                if ($bytes === false || $bytes === '') {
                    continue;
                }
                $data = base64_encode($bytes);
            }
            if (! is_string($data) || $data === '') {
                continue;
            }
            $payloadImages[] = [
                'index' => $image['index'] ?? $i,
                'type' => $image['type'],
                'data' => $data,
            ];
        }

        if (count($payloadImages) < 2) {
            return ['ok' => false, 'payload' => null, 'error' => 'match_needs_two_images'];
        }

        try {
            $response = $this->http()->post($base.'/api/match', ['images' => $payloadImages]);
            if (! $response->successful()) {
                Log::warning('Face match non-success.', ['http_status' => $response->status()]);

                return ['ok' => false, 'payload' => $response->json(), 'error' => 'face_http_'.$response->status()];
            }

            /** @var array<string, mixed>|null $payload */
            $payload = $response->json();

            return ['ok' => true, 'payload' => is_array($payload) ? $payload : null, 'error' => null];
        } catch (\Throwable $e) {
            Log::error('Face match failed: '.$e->getMessage());

            return ['ok' => false, 'payload' => null, 'error' => 'face_unreachable'];
        }
    }

    /**
     * @return array{ok: bool, payload: array<string, mixed>|null, error: string|null}
     */
    public function getLiveness(string $transactionId): array
    {
        $base = $this->baseUrl();
        if ($base === '') {
            return ['ok' => false, 'payload' => null, 'error' => 'face_url_not_configured'];
        }

        try {
            $response = $this->http()->get($base.'/api/v2/liveness', [
                'transactionId' => $transactionId,
            ]);
            if (! $response->successful()) {
                return ['ok' => false, 'payload' => $response->json(), 'error' => 'liveness_http_'.$response->status()];
            }

            /** @var array<string, mixed>|null $payload */
            $payload = $response->json();

            return ['ok' => true, 'payload' => is_array($payload) ? $payload : null, 'error' => null];
        } catch (\Throwable $e) {
            Log::error('Face liveness lookup failed: '.$e->getMessage());

            return ['ok' => false, 'payload' => null, 'error' => 'liveness_unreachable'];
        }
    }

    private function baseUrl(): string
    {
        return rtrim((string) config('services.regula.face_url'), '/');
    }

    private function http(): PendingRequest
    {
        $request = Http::timeout((int) config('services.regula.timeout', 60))
            ->acceptJson()
            ->asJson();

        $apiKey = (string) config('services.regula.api_key');
        if ($apiKey !== '') {
            $request = $request->withToken($apiKey);
        }

        return $request;
    }
}
