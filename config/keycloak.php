<?php

return [
    /*
    | Temporary: log outbound Keycloak HTTP (token + JWKS) and Consul ACL login
    | at INFO. Never includes client_secret, access_token, or SecretID.
    | Set KEYCLOAK_LOG_CALLS=true only while debugging.
    */
    'log_calls' => (bool) env('KEYCLOAK_LOG_CALLS', false),

    /*
    |--------------------------------------------------------------------------
    | Staff authentication via Keycloak (token exchange)
    |--------------------------------------------------------------------------
    |
    | When enabled, staff log in on Keycloak (OIDC, realm pki-portal, client
    | backoffice-stranger) and exchange their access token for a Sanctum token
    | via POST /admin/login/keycloak. Local password login and the staff
    | password endpoints are disabled in that mode.
    | The local users table remains the source of truth for accounts; Spatie
    | staff roles are synced from the JWT on each Keycloak login.
    |
    | KC_STAFF_* must point at pki-portal. Do not fall back to KC_INFRA_*
    | (realm infra / portal-id-foreigner) or an infra JWT would be accepted.
    |
    */

    'staff' => [
        'enabled' => (bool) env('STAFF_KEYCLOAK_ENABLED', false),
        'jwks_uri' => env('KC_STAFF_JWKS'),
        'issuer' => env('KC_STAFF_ISSUER'),
        'audience' => env('KC_STAFF_AUDIENCE', 'backoffice-stranger'),
        'client_id' => env('KC_STAFF_CLIENT_ID', 'backoffice-stranger'),
    ],
];
