<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class StaffKeycloakLoginTest extends TestCase
{
    use RefreshDatabase;

    private const JWKS_URI = 'https://demo-oauth.qcdigitalhub.com/realms/pki-portal/protocol/openid-connect/certs';

    private const ISSUER = 'https://demo-oauth.qcdigitalhub.com/realms/pki-portal';

    private string $privatePem;

    /** @var array<string, string> */
    private array $jwk;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        Http::preventStrayRequests();

        $this->bootRsaKey();

        config([
            'keycloak.staff.enabled' => true,
            'keycloak.staff.jwks_uri' => self::JWKS_URI,
            'keycloak.staff.issuer' => self::ISSUER,
            'keycloak.staff.audience' => 'backoffice-stranger',
            'keycloak.staff.client_id' => 'backoffice-stranger',
        ]);

        Http::fake([
            self::JWKS_URI => Http::response(['keys' => [$this->jwk]]),
        ]);

        foreach (config('roles.staff', []) as $role) {
            Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        }
    }

    #[Test]
    public function keycloak_login_issues_sanctum_token_and_syncs_role(): void
    {
        $user = User::factory()->create([
            'email' => 'agent.kc@example.com',
            'status' => 'ACTIVE',
        ]);
        $user->assignRole(config('roles.agent'));

        $token = $this->signStaffJwt([
            'email' => $user->email,
            'realm_access' => ['roles' => ['manager', 'offline_access', 'default-roles-pki-portal']],
        ]);

        $this->postJson($this->api('/admin/login/keycloak'), ['access_token' => $token])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.user.email', $user->email)
            ->assertJsonPath('data.user.role', 'MANAGER')
            ->assertJsonStructure(['data' => ['access_token', 'roles', 'user']]);

        $user->refresh();
        $this->assertTrue($user->hasRole(config('roles.manager')));
        $this->assertFalse($user->hasRole(config('roles.agent')));
    }

    #[Test]
    public function keycloak_login_rejects_unknown_email(): void
    {
        $token = $this->signStaffJwt([
            'email' => 'unknown.staff@example.com',
            'realm_access' => ['roles' => ['agent']],
        ]);

        $this->postJson($this->api('/admin/login/keycloak'), ['access_token' => $token])
            ->assertForbidden()
            ->assertJsonPath('success', false);
    }

    #[Test]
    public function keycloak_login_rejects_jwt_without_staff_role(): void
    {
        $user = User::factory()->create([
            'email' => 'agent.norole@example.com',
            'status' => 'ACTIVE',
        ]);
        $user->assignRole(config('roles.agent'));

        $token = $this->signStaffJwt([
            'email' => $user->email,
            'realm_access' => ['roles' => ['offline_access', 'default-roles-pki-portal']],
        ]);

        $this->postJson($this->api('/admin/login/keycloak'), ['access_token' => $token])
            ->assertForbidden()
            ->assertJsonPath('success', false);

        $user->refresh();
        $this->assertTrue($user->hasRole(config('roles.agent')));
    }

    #[Test]
    public function keycloak_login_forbidden_when_flag_is_off(): void
    {
        config(['keycloak.staff.enabled' => false]);

        $user = User::factory()->create([
            'email' => 'agent.flagoff@example.com',
            'status' => 'ACTIVE',
        ]);
        $user->assignRole(config('roles.agent'));

        $token = $this->signStaffJwt([
            'email' => $user->email,
            'realm_access' => ['roles' => ['agent']],
        ]);

        $this->postJson($this->api('/admin/login/keycloak'), ['access_token' => $token])
            ->assertForbidden()
            ->assertJsonPath('message', 'Authentification Keycloak non activée.');
    }

    #[Test]
    public function password_login_forbidden_when_keycloak_staff_is_on(): void
    {
        $user = User::factory()->create([
            'email' => 'agent.password@example.com',
            'password' => 'password',
            'status' => 'ACTIVE',
        ]);
        $user->assignRole(config('roles.agent'));

        $this->postJson($this->api('/admin/login'), [
            'email' => $user->email,
            'password' => 'password',
        ])
            ->assertForbidden()
            ->assertJsonPath('message', 'Authentification via Keycloak requise.');
    }

    /**
     * @param  array<string, mixed>  $claims
     */
    private function signStaffJwt(array $claims): string
    {
        $header = ['alg' => 'RS256', 'typ' => 'JWT', 'kid' => 'staff-test-key'];
        $payload = array_merge([
            'iss' => self::ISSUER,
            'aud' => 'backoffice-stranger',
            'exp' => time() + 3600,
            'iat' => time(),
        ], $claims);

        $encodedHeader = $this->base64UrlEncode(json_encode($header, JSON_THROW_ON_ERROR));
        $encodedPayload = $this->base64UrlEncode(json_encode($payload, JSON_THROW_ON_ERROR));
        $signed = $encodedHeader.'.'.$encodedPayload;

        $ok = openssl_sign($signed, $signature, $this->privatePem, OPENSSL_ALGO_SHA256);
        $this->assertTrue($ok);

        return $signed.'.'.$this->base64UrlEncode($signature);
    }

    private function bootRsaKey(): void
    {
        $key = openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);
        $this->assertNotFalse($key);

        $exported = openssl_pkey_export($key, $pem);
        $this->assertTrue($exported);
        $this->assertIsString($pem);
        $this->privatePem = $pem;

        $details = openssl_pkey_get_details($key);
        $this->assertIsArray($details);
        $this->assertArrayHasKey('rsa', $details);

        $this->jwk = [
            'kty' => 'RSA',
            'kid' => 'staff-test-key',
            'use' => 'sig',
            'alg' => 'RS256',
            'n' => $this->base64UrlEncode($details['rsa']['n']),
            'e' => $this->base64UrlEncode($details['rsa']['e']),
        ];
    }

    private function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
}
