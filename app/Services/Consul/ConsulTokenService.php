<?php

declare(strict_types=1);

namespace App\Services\Consul;

use App\Exceptions\ConsulException;
use App\Support\KeycloakCallLogger;
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
        $url = (string) config('consul.keycloak.token_uri');
        $response = Http::asForm()->timeout($this->timeoutSeconds())->post(
            $url,
            [
                'grant_type' => 'client_credentials',
                'client_id' => config('consul.keycloak.client_id'),
                'client_secret' => config('consul.keycloak.client_secret'),
            ]
        );

        $accessToken = $response->json('access_token');
        KeycloakCallLogger::keycloak('token', 'POST', $url, $response->status(), [
            'grant_type' => 'client_credentials',
            'client_id' => config('consul.keycloak.client_id'),
            'token_returned' => is_string($accessToken) && $accessToken !== '',
        ]);

        $response->throw();

        if (! is_string($accessToken) || $accessToken === '') {
            throw new ConsulException('Keycloak n\'a pas retourné de jeton d\'accès (access_token).');
        }

        return $accessToken;
    }

    private function exchangeForConsulToken(string $keycloakToken): string
    {
        $url = rtrim((string) config('consul.url'), '/').'/v1/acl/login';
        $request = Http::timeout($this->timeoutSeconds())->withHeaders(['Content-Type' => 'application/json']);

        if ($cacert = config('consul.cacert')) {
            $request = $request->withOptions(['verify' => $cacert]);
        }

        $authMethod = config('consul.keycloak.auth_method');
        $response = $request->post(
            $url,
            [
                'AuthMethod' => $authMethod,
                'BearerToken' => $keycloakToken,
            ]
        );

        $secretId = $response->json('SecretID');
        KeycloakCallLogger::consulAclLogin(
            $url,
            $response->status(),
            is_string($authMethod) ? $authMethod : '',
            [
                'secret_returned' => is_string($secretId) && $secretId !== '',
            ],
        );

        $response->throw();

        if (! is_string($secretId) || $secretId === '') {
            throw new ConsulException('Consul n\'a pas retourné de SecretID.');
        }

        return $secretId;
    }

    private function timeoutSeconds(): int
    {
        $timeout = (int) config('consul.timeout', 10);

        return $timeout > 0 ? $timeout : 10;
    }
}
