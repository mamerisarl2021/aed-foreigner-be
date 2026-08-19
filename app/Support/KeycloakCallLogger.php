<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Facades\Log;

final class KeycloakCallLogger
{
    /**
     * @param  array<string, mixed>  $context
     */
    public static function keycloak(string $operation, string $method, string $url, int $statusCode, array $context = []): void
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
    public static function consulAclLogin(string $url, int $statusCode, string $authMethod, array $context = []): void
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
