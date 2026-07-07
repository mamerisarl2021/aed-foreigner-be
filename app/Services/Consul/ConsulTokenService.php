<?php

declare(strict_types=1);

namespace App\Services\Consul;

use App\Exceptions\ConsulException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

final class ConsulTokenService
{
    public function getToken(): string
    {
        if ($directToken = config('consul.http_token')) {
            return $directToken;
        }

        if (config('consul.scheme') === 'http' && app()->environment('local')) {
            return '';
        }

        return Cache::remember('consul.acl_token', now()->addMinutes(4), function (): string {
            $keycloakToken = $this->getKeycloakToken();

            return $this->exchangeForConsulToken($keycloakToken);
        });
    }

    private function getKeycloakToken(): string
    {
        $response = Http::asForm()->post(
            (string) config('consul.keycloak.token_uri'),
            [
                'grant_type' => 'client_credentials',
                'client_id' => config('consul.keycloak.client_id'),
                'client_secret' => config('consul.keycloak.client_secret'),
            ]
        );

        $response->throw();

        $accessToken = $response->json('access_token');

        if (! is_string($accessToken) || $accessToken === '') {
            throw new ConsulException('Keycloak n\'a pas retourné de jeton d\'accès (access_token).');
        }

        return $accessToken;
    }

    private function exchangeForConsulToken(string $keycloakToken): string
    {
        $request = Http::withHeaders(['Content-Type' => 'application/json']);

        if ($cacert = config('consul.cacert')) {
            $request = $request->withOptions(['verify' => $cacert]);
        }

        $response = $request->post(
            rtrim((string) config('consul.url'), '/').'/v1/acl/login',
            [
                'AuthMethod' => config('consul.keycloak.auth_method'),
                'BearerToken' => $keycloakToken,
            ]
        );

        $response->throw();

        $secretId = $response->json('SecretID');

        if (! is_string($secretId) || $secretId === '') {
            throw new ConsulException('Consul n\'a pas retourné de SecretID.');
        }

        return $secretId;
    }
}
