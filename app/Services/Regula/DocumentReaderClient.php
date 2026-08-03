<?php

declare(strict_types=1);

namespace App\Services\Regula;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Thin client for Regula Document Reader Web Service (not exposed as AED public routes).
 *
 * @see https://docs.regulaforensics.com/develop/doc-reader-sdk/web-service/development/usage/process/
 */
final class DocumentReaderClient
{
    public function healthz(): bool
    {
        $base = $this->baseUrl();
        if ($base === '') {
            return false;
        }

        try {
            return $this->http()->get($base.'/api/healthz')->successful();
        } catch (\Throwable $e) {
            Log::warning('Document Reader healthz failed: '.$e->getMessage());

            return false;
        }
    }

    /**
     * @param  list<string>  $imagePaths  Absolute paths to document page images (recto, verso, …)
     * @return array{ok: bool, payload: array<string, mixed>|null, error: string|null}
     */
    public function process(array $imagePaths, ?string $scenario = null): array
    {
        $base = $this->baseUrl();
        if ($base === '') {
            return ['ok' => false, 'payload' => null, 'error' => 'document_url_not_configured'];
        }

        $list = [];
        foreach (array_values($imagePaths) as $index => $path) {
            if (! is_string($path) || ! is_file($path)) {
                continue;
            }
            $bytes = file_get_contents($path);
            if ($bytes === false || $bytes === '') {
                continue;
            }
            $list[] = [
                'ImageData' => ['image' => base64_encode($bytes)],
                'page_idx' => $index,
            ];
        }

        if ($list === []) {
            return ['ok' => false, 'payload' => null, 'error' => 'no_document_images'];
        }

        $body = [
            'processParam' => [
                'scenario' => $scenario ?: (string) config('services.regula.document_scenario', 'FullProcess'),
            ],
            'List' => $list,
        ];

        try {
            $response = $this->http()->post($base.'/api/process', $body);
            if (! $response->successful()) {
                Log::warning('Document Reader process non-success.', ['http_status' => $response->status()]);

                return ['ok' => false, 'payload' => $response->json(), 'error' => 'document_http_'.$response->status()];
            }

            /** @var array<string, mixed>|null $payload */
            $payload = $response->json();

            return ['ok' => true, 'payload' => is_array($payload) ? $payload : null, 'error' => null];
        } catch (\Throwable $e) {
            Log::error('Document Reader process failed: '.$e->getMessage());

            return ['ok' => false, 'payload' => null, 'error' => 'document_unreachable'];
        }
    }

    private function baseUrl(): string
    {
        return rtrim((string) config('services.regula.document_url'), '/');
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
