<?php

namespace App\Services\ANIP;

use GuzzleHttp\Exception\ClientException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class AnipSimulatorService
{
    private $ANIP_BASE_URL;

    public function __construct()
    {
        $this->ANIP_BASE_URL = config('trustedx.anip_base_url');
    }

    public function getUserData(string $npi)
    {
        try {
            $response = Http::withBasicAuth('admin', 'supersecret')->get("https://local-simulator.qcdigitalhub.com/api/user/{$npi}");

            if ($response->successful()) {
                return [
                    'status' => true,
                    'data' => [
                        ...$response->json(),
                        'role' => 'CLIENT',
                    ],
                ];
            } else {
                return [
                    'status' => false,
                    'message' => 'Ce NPI ne correspond à aucun utilisateur.',
                ];
            }
        } catch (ClientException $e) {
            Log::error($e->getMessage(), $e->getTrace());

            return ['error' => $e->getMessage()];
        }
    }
}
