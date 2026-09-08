<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Enums\ActivityLogAction;
use App\Models\ActivityLog;
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
            'sub' => 'kc-subject-1',
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
        // Compte antérieur à la colonne : le sujet est rattaché au passage.
        $this->assertSame('kc-subject-1', $user->keycloak_id);
    }

    #[Test]
    public function keycloak_login_provisions_an_unknown_staff_user(): void
    {
        $token = $this->signStaffJwt([
            'sub' => 'kc-subject-new',
            'email' => 'nouvelle.agente@example.com',
            'given_name' => 'Ada',
            'family_name' => 'KOTO',
            'realm_access' => ['roles' => ['agent']],
        ]);

        $this->postJson($this->api('/admin/login/keycloak'), ['access_token' => $token])
            ->assertOk()
            ->assertJsonPath('data.user.email', 'nouvelle.agente@example.com')
            ->assertJsonPath('data.user.nom', 'KOTO')
            ->assertJsonPath('data.user.prenom', 'Ada')
            ->assertJsonPath('data.user.role', 'AGENT');

        $user = User::query()->where('email', 'nouvelle.agente@example.com')->firstOrFail();
        $this->assertSame('kc-subject-new', $user->keycloak_id);
        $this->assertSame('ACTIVE', $user->status);
        $this->assertTrue($user->hasRole(config('roles.agent')));

        // Le provisioning est un acte d'administration : il doit être traçable
        // au même titre que la connexion qui l'a déclenché.
        foreach ([ActivityLogAction::UtilisateurCree, ActivityLogAction::ConnexionAdmin] as $action) {
            $this->assertTrue(
                ActivityLog::query()
                    ->where('action_code', $action->label())
                    ->where('actor_user_id', $user->id)
                    ->exists(),
                "Journal manquant pour {$action->value}."
            );
        }
    }

    #[Test]
    public function keycloak_login_rejects_jwt_without_staff_role(): void
    {
        $token = $this->signStaffJwt([
            'sub' => 'kc-subject-norole',
            'email' => 'sans.role@example.com',
            'realm_access' => ['roles' => ['offline_access', 'default-roles-pki-portal']],
        ]);

        $this->postJson($this->api('/admin/login/keycloak'), ['access_token' => $token])
            ->assertForbidden()
            ->assertJsonPath('success', false);

        // Aucun rôle staff : rien ne doit être provisionné.
        $this->assertDatabaseMissing('users', ['email' => 'sans.role@example.com']);
    }

    #[Test]
    public function keycloak_login_does_not_downgrade_an_existing_user_without_staff_role(): void
    {
        $user = User::factory()->create([
            'email' => 'agent.norole@example.com',
            'status' => 'ACTIVE',
        ]);
        $user->assignRole(config('roles.agent'));

        $token = $this->signStaffJwt([
            'sub' => 'kc-subject-2',
            'email' => $user->email,
            'realm_access' => ['roles' => ['offline_access']],
        ]);

        $this->postJson($this->api('/admin/login/keycloak'), ['access_token' => $token])
            ->assertForbidden();

        $user->refresh();
        $this->assertTrue($user->hasRole(config('roles.agent')));
    }

    #[Test]
    public function keycloak_login_reactivates_a_locally_inactive_account(): void
    {
        $user = User::factory()->create([
            'email' => 'agent.inactif@example.com',
            'status' => 'INACTIVE',
        ]);
        $user->assignRole(config('roles.agent'));

        $token = $this->signStaffJwt([
            'sub' => 'kc-subject-3',
            'email' => $user->email,
            'realm_access' => ['roles' => ['agent']],
        ]);

        // La désactivation se fait dans Keycloak, qui n'émettrait alors pas de
        // token : un statut local ne peut plus fermer la porte.
        $this->postJson($this->api('/admin/login/keycloak'), ['access_token' => $token])
            ->assertOk();

        $this->assertSame('ACTIVE', $user->refresh()->status);
    }

    #[Test]
    public function keycloak_login_follows_the_subject_when_the_email_changes(): void
    {
        $user = User::factory()->create([
            'email' => 'ancienne.adresse@example.com',
            'keycloak_id' => 'kc-subject-stable',
            'status' => 'ACTIVE',
        ]);
        $user->assignRole(config('roles.agent'));

        $token = $this->signStaffJwt([
            'sub' => 'kc-subject-stable',
            'email' => 'nouvelle.adresse@example.com',
            'realm_access' => ['roles' => ['agent']],
        ]);

        $this->postJson($this->api('/admin/login/keycloak'), ['access_token' => $token])
            ->assertOk()
            ->assertJsonPath('data.user.id', $user->id);

        $this->assertSame('nouvelle.adresse@example.com', $user->refresh()->email);
        $this->assertSame(1, User::query()->where('keycloak_id', 'kc-subject-stable')->count());
    }

    #[Test]
    public function keycloak_login_revokes_open_sessions_when_roles_change(): void
    {
        $user = User::factory()->create([
            'email' => 'agent.retrograde@example.com',
            'status' => 'ACTIVE',
        ]);
        $user->assignRole(config('roles.manager'));
        $user->createToken('session-ouverte');

        $token = $this->signStaffJwt([
            'sub' => 'kc-subject-4',
            'email' => $user->email,
            'realm_access' => ['roles' => ['agent']],
        ]);

        $this->postJson($this->api('/admin/login/keycloak'), ['access_token' => $token])
            ->assertOk();

        // Un rôle retiré dans Keycloak ne doit pas survivre dans un jeton Sanctum
        // encore valide : seule la session qui vient d'être ouverte subsiste.
        $this->assertSame(1, $user->tokens()->count());
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
