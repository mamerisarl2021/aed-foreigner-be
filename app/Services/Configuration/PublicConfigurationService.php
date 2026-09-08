<?php

declare(strict_types=1);

namespace App\Services\Configuration;

final class PublicConfigurationService
{
    /**
     * Public OIDC bootstrap for the SPA. Never includes client_secret.
     *
     * @return array{
     *     TX_BASE_URL: string,
     *     TX_CLIENT_ID: string,
     *     TX_SCOPE: string,
     *     TX_REDIRECT_PATH: string,
     *     TX_ADMINS_LOGGED_AS: string,
     *     TX_CLIENTS_LOGGED_AS: string,
     *     KC_STAFF_ISSUER: string,
     *     KC_STAFF_CLIENT_ID: string,
     *     KC_STAFF_SCOPE: string
     * }
     */
    public function publicPayload(): array
    {
        return [
            'TX_BASE_URL' => $this->publicTrustedXBaseUrl(),
            'TX_CLIENT_ID' => (string) config('trustedx.client_id'),
            'TX_SCOPE' => (string) config('trustedx.scope'),
            'TX_REDIRECT_PATH' => $this->redirectPath(),
            'TX_ADMINS_LOGGED_AS' => (string) config('trustedx.admins_logged_as'),
            'TX_CLIENTS_LOGGED_AS' => (string) config('trustedx.clients_logged_as'),
            'KC_STAFF_ISSUER' => (string) config('keycloak.staff.issuer'),
            'KC_STAFF_CLIENT_ID' => (string) config('keycloak.staff.client_id'),
            'KC_STAFF_SCOPE' => (string) config('keycloak.staff.scope'),
        ];
    }

    private function publicTrustedXBaseUrl(): string
    {
        $raw = trim((string) config('trustedx.base_url'));
        if ($raw === '') {
            return '';
        }

        if (preg_match('#^https?://#i', $raw) === 1) {
            return rtrim($raw, '/');
        }

        return 'https://'.ltrim($raw, '/');
    }

    private function redirectPath(): string
    {
        $explicit = trim((string) config('trustedx.redirect_path'));
        if ($explicit !== '') {
            return $explicit;
        }

        $path = parse_url((string) config('trustedx.redirect_url'), PHP_URL_PATH);

        return is_string($path) ? $path : '';
    }
}
