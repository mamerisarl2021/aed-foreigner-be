<?php

declare(strict_types=1);

namespace App\Services\ANIP;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class AnipSimulatorService
{
    private string $baseUrl;

    public function __construct()
    {
        $this->baseUrl = rtrim((string) config('trustedx.anip_base_url'), '/');
    }

    /**
     * @return array<string, mixed>
     */
    public function getUserData(string $npi): array
    {
        try {
            $response = Http::timeout(10)
                ->withBasicAuth(
                    (string) config('trustedx.anip_username'),
                    (string) config('trustedx.anip_password'),
                )->get("{$this->baseUrl}/api/user/{$npi}");

            if ($response->successful()) {
                return [
                    'status' => true,
                    'data' => [
                        ...$response->json(),
                        'role' => 'CLIENT',
                    ],
                ];
            }

            return [
                'status' => false,
                'message' => 'Ce NPI ne correspond à aucun utilisateur.',
            ];
        } catch (Throwable $e) {
            Log::error($e->getMessage(), $e->getTrace());

            return ['status' => false, 'message' => 'Service ANIP indisponible.'];
        }
    }
}
