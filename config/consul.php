<?php

return [
    'url' => env('CONSUL_URL', 'http://localhost:8500'),
    'scheme' => env('CONSUL_SCHEME', 'http'),
    'host' => env('CONSUL_HOST', 'localhost'),
    'port' => (int) env('CONSUL_PORT', 8500),
    'http_token' => env('CONSUL_HTTP_TOKEN'),
    'cacert' => env('CONSUL_CACERT'),

    'service_name' =>env('CONSUL_SERVICE_NAME', 'portal-id-foreigner'),
    'service_ip' => env('CONSUL_SERVICE_IP', gethostbyname(gethostname())),
    'service_port' => (int) env('CONSUL_SERVICE_PORT',8000),

    'health_path' => env('CONSUL_HEALTH_PATH', '/api/v1/health'),
    'check_interval' => env('CONSUL_CHECK_INTERVAL', '10s'),
    'check_timeout' => env('CONSUL_CHECK_TIMEOUT', '2s'),
    'deregister_after' => env('CONSUL_DEREGISTER_CRITICAL_AFTER', '1m'),

    'tags' => ['asin', 'v1', 'laravel'],

    'service_id_file' => storage_path('app/consul-service-id.json'),

//    'keycloak' => [
//        // Fail closed: the gateway token check is on unless explicitly disabled (local dev).
//        'enabled' => env('KEYCLOAK_ENABLED', true),
//        'token_uri' => env('KC_INFRA_TOKEN_URI'),
//        'jwks_uri' => env('KC_INFRA_JWKS'),
//        'issuer' => env('KC_INFRA_ISSUER'),
//        'audience' => env('KC_INFRA_AUDIENCE'),
//        'client_id' => env('KC_INFRA_CLIENT_ID'),
//        'client_secret' => env('KC_INFRA_SECRET'),
//        'auth_method' => env('CONSUL_ACL_AUTH_METHOD', 'keycloak-infra-svc'),
//    ],
];
