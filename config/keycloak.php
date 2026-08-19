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
    | User CRUD is pushed to the Keycloak Admin API via a confidential service
    | account (KC_STAFF_ADMIN_*), never via the public SPA client.
    |
    */

    'staff' => [
        'enabled' => (bool) env('STAFF_KEYCLOAK_ENABLED', false),
        'jwks_uri' => env('KC_STAFF_JWKS'),
        'issuer' => env('KC_STAFF_ISSUER'),
        'audience' => env('KC_STAFF_AUDIENCE', 'backoffice-stranger'),
        'client_id' => env('KC_STAFF_CLIENT_ID', 'backoffice-stranger'),
        'token_uri' => env('KC_STAFF_TOKEN_URI'),
        'admin_client_id' => env('KC_STAFF_ADMIN_CLIENT_ID', 'backoffice-staff-admin'),
        'admin_client_secret' => env('KC_STAFF_ADMIN_SECRET'),
        // Must be a Valid Redirect URI of the SPA client (backoffice-stranger).
        'actions_redirect_uri' => env('KC_STAFF_ACTIONS_REDIRECT_URI'),
        // Keycloak realm SMTP. Leave false until pki-portal Email is configured:
        // Laravel then emails the initial password via WelcomeAgentJob (log/kafka).
        'execute_actions_email' => (bool) env('KC_STAFF_EXECUTE_ACTIONS_EMAIL', false),
    ],
];
