<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Auth;

use App\Services\Auth\KeycloakJwtValidator;
use App\Services\Consul\ConsulTokenService;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

final class KeycloakCallLogTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        config([
            'consul.http_token' => null,
            'consul.scheme' => 'https',
            'consul.url' => 'https://consul.example.test',
            'consul.cacert' => null,
            'consul.keycloak.token_uri' => 'https://kc.example.test/token',
            'consul.keycloak.client_id' => 'portal-id-foreigner',
            'consul.keycloak.client_secret' => 'not-logged',
            'consul.keycloak.auth_method' => 'keycloak-infra-svc',
            'consul.keycloak.jwks_uri' => 'https://kc.example.test/certs',
        ]);
    }

    #[Test]
    public function get_token_logs_keycloak_and_consul_when_flag_is_on(): void
    {
        config(['keycloak.log_calls' => true]);

        $recorded = [];
        Event::listen(MessageLogged::class, function (MessageLogged $event) use (&$recorded): void {
            $recorded[] = $event;
        });

        Http::fake([
            'https://kc.example.test/token' => Http::response(['access_token' => 'jwt-must-not-appear'], 200),
            'https://consul.example.test/v1/acl/login' => Http::response(['SecretID' => 'secret-must-not-appear'], 200),
        ]);

        $token = (new ConsulTokenService)->getToken();

        $this->assertSame('secret-must-not-appear', $token);

        $keycloak = collect($recorded)->first(
            fn (MessageLogged $log): bool => $log->message === 'Keycloak call'
                && ($log->context['operation'] ?? null) === 'token'
        );
        $this->assertNotNull($keycloak);
        $this->assertSame('POST', $keycloak->context['method'] ?? null);
        $this->assertSame('https://kc.example.test/token', $keycloak->context['url'] ?? null);
        $this->assertSame(200, $keycloak->context['status'] ?? null);
        $this->assertSame('client_credentials', $keycloak->context['grant_type'] ?? null);
        $this->assertSame('portal-id-foreigner', $keycloak->context['client_id'] ?? null);
        $this->assertTrue((bool) ($keycloak->context['token_returned'] ?? false));
        $this->assertArrayNotHasKey('access_token', $keycloak->context);
        $this->assertArrayNotHasKey('client_secret', $keycloak->context);

        $acl = collect($recorded)->first(
            fn (MessageLogged $log): bool => $log->message === 'Consul ACL login'
        );
        $this->assertNotNull($acl);
        $this->assertSame('keycloak-infra-svc', $acl->context['auth_method'] ?? null);
        $this->assertTrue((bool) ($acl->context['secret_returned'] ?? false));
        $this->assertArrayNotHasKey('SecretID', $acl->context);
        $this->assertArrayNotHasKey('BearerToken', $acl->context);
    }

    #[Test]
    public function get_token_does_not_log_when_flag_is_off(): void
    {
        config(['keycloak.log_calls' => false]);

        $recorded = [];
        Event::listen(MessageLogged::class, function (MessageLogged $event) use (&$recorded): void {
            $recorded[] = $event;
        });

        Http::fake([
            'https://kc.example.test/token' => Http::response(['access_token' => 'jwt'], 200),
            'https://consul.example.test/v1/acl/login' => Http::response(['SecretID' => 'secret'], 200),
        ]);

        (new ConsulTokenService)->getToken();

        $this->assertNull(collect($recorded)->first(
            fn (MessageLogged $log): bool => in_array($log->message, ['Keycloak call', 'Consul ACL login'], true)
        ));
    }

    #[Test]
    public function jwks_fetch_logs_when_flag_is_on_even_if_signature_fails(): void
    {
        config(['keycloak.log_calls' => true]);

        $recorded = [];
        Event::listen(MessageLogged::class, function (MessageLogged $event) use (&$recorded): void {
            $recorded[] = $event;
        });

        Http::fake([
            'https://kc.example.test/certs' => Http::response([
                'keys' => [['kid' => 'other-kid', 'kty' => 'RSA', 'n' => 'AQAB', 'e' => 'AQAB']],
            ], 200),
        ]);

        try {
            (new KeycloakJwtValidator)->validate($this->unsignedJwt('test-kid'));
        } catch (RuntimeException) {
        }

        $jwks = collect($recorded)->first(
            fn (MessageLogged $log): bool => $log->message === 'Keycloak call'
                && ($log->context['operation'] ?? null) === 'jwks'
        );
        $this->assertNotNull($jwks);
        $this->assertSame('GET', $jwks->context['method'] ?? null);
        $this->assertSame('https://kc.example.test/certs', $jwks->context['url'] ?? null);
        $this->assertSame(200, $jwks->context['status'] ?? null);
        $this->assertSame('test-kid', $jwks->context['kid'] ?? null);
        $this->assertFalse((bool) ($jwks->context['cache_hit'] ?? true));
    }

    #[Test]
    public function jwks_does_not_log_when_flag_is_off(): void
    {
        config(['keycloak.log_calls' => false]);

        $recorded = [];
        Event::listen(MessageLogged::class, function (MessageLogged $event) use (&$recorded): void {
            $recorded[] = $event;
        });

        Http::fake([
            'https://kc.example.test/certs' => Http::response(['keys' => []], 200),
        ]);

        try {
            (new KeycloakJwtValidator)->validate($this->unsignedJwt('test-kid'));
        } catch (RuntimeException) {
        }

        $this->assertNull(collect($recorded)->first(
            fn (MessageLogged $log): bool => $log->message === 'Keycloak call'
        ));
    }

    private function unsignedJwt(string $kid): string
    {
        $header = $this->base64UrlEncode((string) json_encode(['alg' => 'RS256', 'kid' => $kid], JSON_THROW_ON_ERROR));
        $payload = $this->base64UrlEncode((string) json_encode(['exp' => time() + 3600], JSON_THROW_ON_ERROR));

        return $header.'.'.$payload.'.fakesig';
    }

    private function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
}
