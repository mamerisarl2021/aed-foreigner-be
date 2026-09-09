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
    | Keycloak (OIDC, realm pki-portal, client backoffice-stranger) est le seul
    | annuaire du staff. Le back-office échange l'access token contre un jeton
    | Sanctum via POST /admin/login/keycloak ; l'application ne crée, ne modifie
    | et ne supprime plus aucun compte, et ne détient plus de mot de passe staff.
    | La ligne locale n'est qu'une projection du JWT, rattachée au claim `sub`
    | (users.keycloak_id) ; les rôles Spatie que lisent les policies sont
    | réécrits depuis le token à chaque connexion.
    |
    | Le changement de mot de passe à la première connexion se configure côté
    | realm (action requise UPDATE_PASSWORD, ou mot de passe « Temporary ») :
    | Keycloak n'émet aucun token tant qu'elle n'est pas jouée.
    |
    | KC_STAFF_* doit pointer sur pki-portal. Ne jamais retomber sur KC_INFRA_*
    | (realm infra / portal-id-foreigner), un JWT infra serait accepté.
    |
    */

    'staff' => [
        'jwks_uri' => env('KC_STAFF_JWKS'),
        'issuer' => env('KC_STAFF_ISSUER'),
        'audience' => env('KC_STAFF_AUDIENCE', 'backoffice-stranger'),
        'client_id' => env('KC_STAFF_CLIENT_ID', 'backoffice-stranger'),
        'scope' => env('KC_STAFF_SCOPE', 'openid profile email'),
    ],
];
