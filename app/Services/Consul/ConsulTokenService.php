<?php

namespace App\Services\Consul;

use App\Exceptions\ConsulException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

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
        $response = Http::asForm()->post(
            $url,
            [
                'grant_type' => 'client_credentials',
                'client_id' => config('consul.keycloak.client_id'),
                'client_secret' => config('consul.keycloak.client_secret'),
            ]
        );

        $accessToken = $response->json('access_token');
        $this->logKeycloakCall('token', 'POST', $url, $response->status(), [
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
        $request = Http::withHeaders(['Content-Type' => 'application/json']);

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
        $this->logConsulAclLogin($url, $response->status(), is_string($authMethod) ? $authMethod : '', [
            'secret_returned' => is_string($secretId) && $secretId !== '',
        ]);

        $response->throw();

        if (! is_string($secretId) || $secretId === '') {
            throw new ConsulException('Consul n\'a pas retourné de SecretID.');
        }

        return $secretId;
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function logKeycloakCall(string $operation, string $method, string $url, int $statusCode, array $context = []): void
    {
        if (! config('keycloak.log_calls')) {
            return;
        }

        Log::info('Keycloak call', array_merge([
            'operation' => $operation,
            'method' => $method,
            'url' => $url,
            'status' => $statusCode,
        ], $context));
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function logConsulAclLogin(string $url, int $statusCode, string $authMethod, array $context = []): void
    {
        if (! config('keycloak.log_calls')) {
            return;
        }

        Log::info('Consul ACL login', array_merge([
            'operation' => 'acl_login',
            'method' => 'POST',
            'url' => $url,
            'status' => $statusCode,
            'auth_method' => $authMethod,
        ], $context));
    }
}
