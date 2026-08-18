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
    | When enabled, staff log in on Keycloak (OIDC) and exchange their access
    | token for a Sanctum token via POST /admin/login/keycloak. Local password
    | login and the staff password endpoints are disabled in that mode.
    | The local users table remains the source of truth for accounts and roles.
    |
    | KC_STAFF_* values fall back to the KC_INFRA_* realm settings so a single
    | realm configuration works out of the box.
    |
    */

    'staff' => [
        'enabled' => (bool) env('STAFF_KEYCLOAK_ENABLED', false),
        'jwks_uri' => env('KC_STAFF_JWKS', env('KC_INFRA_JWKS')),
        'issuer' => env('KC_STAFF_ISSUER', env('KC_INFRA_ISSUER')),
        'audience' => env('KC_STAFF_AUDIENCE', env('KC_INFRA_AUDIENCE')),
    ],
];
