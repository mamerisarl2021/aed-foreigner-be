<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Models\User;
use App\Services\Registration\UserRegistrationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class ClientLoginTrustedXTest extends TestCase
{
    use RefreshDatabase;

    private const REDIRECT_URI = 'http://localhost:4200/etranger/connexion/callback';

    private User $client;

    protected function setUp(): void
    {
        parent::setUp();

        Role::firstOrCreate(['name' => config('roles.client'), 'guard_name' => 'web']);

        $this->client = User::factory()->create([
            'email' => 'client-login@example.com',
            'npi' => '9876543210123',
            'name' => 'NOM_LOCAL',
            'first_name' => 'Prenom_local',
            'status' => 'ACTIVE',
        ]);
        $this->client->assignRole(config('roles.client'));
    }

    #[Test]
    public function client_login_returns_trustedx_profile_and_stamps_last_login(): void
    {
        $this->fakeTrustedX();

        $result = app(UserRegistrationService::class)->login('auth-code', self::REDIRECT_URI);

        $this->assertTrue($result->success, $result->message);

        /** @var array{user: User, token: string, pki_token: string} $data */
        $data = $result->data;

        $this->assertSame('pki-access-token', $data['pki_token']);
        $this->assertNotEmpty($data['token']);

        $payload = $data['user']->toArray();
        $this->assertSame('DOSSOU', $payload['name']);
        $this->assertSame('Marc', $payload['first_name']);
        $this->assertSame('Aurele', $payload['last_name']);
        $this->assertSame('tx-subject-id', $payload['pki_id']);

        $this->client->refresh();
        $this->assertNotNull($this->client->last_login_at);
    }

    #[Test]
    public function client_login_does_not_persist_trustedx_only_fields(): void
    {
        $this->fakeTrustedX();

        $result = app(UserRegistrationService::class)->login('auth-code', self::REDIRECT_URI);

        $this->assertTrue($result->success, $result->message);

        $row = (array) DB::table('users')->where('id', $this->client->id)->first();

        $this->assertArrayNotHasKey('last_name', $row);
        $this->assertArrayNotHasKey('pki_id', $row);
        $this->assertSame('NOM_LOCAL', $row['name']);
        $this->assertSame('Prenom_local', $row['first_name']);
    }

    #[Test]
    public function mobile_login_returns_trustedx_profile_and_stamps_last_login(): void
    {
        $this->fakeTrustedX();

        $result = app(UserRegistrationService::class)->loginMobile('auth-code');

        $this->assertTrue($result->success, $result->message);

        /** @var array{user: User, token: string, pki_token: string} $data */
        $data = $result->data;

        $payload = $data['user']->toArray();
        $this->assertSame('Aurele', $payload['last_name']);
        $this->assertSame('tx-subject-id', $payload['pki_id']);

        $this->client->refresh();
        $this->assertNotNull($this->client->last_login_at);
    }

    #[Test]
    public function client_login_sends_the_redirect_uri_it_was_given(): void
    {
        $this->fakeTrustedX();

        app(UserRegistrationService::class)->login('auth-code', self::REDIRECT_URI);

        Http::assertSent(fn ($request): bool => str_contains($request->url(), 'oauth')
            && str_contains($request->url(), 'redirect_uri='.self::REDIRECT_URI));
    }

    /**
     * A redirect_uri outside the allow-list must never reach TrustedX: the code
     * is single-use, so a rejected exchange would burn it for nothing.
     */
    #[Test]
    public function client_login_rejects_a_redirect_uri_outside_the_allow_list(): void
    {
        config(['trustedx.allowed_redirect_urls' => [self::REDIRECT_URI]]);
        Http::fake();

        $response = $this->postJson($this->api('/clients/login'), [
            'code' => 'auth-code',
            'redirect_uri' => 'http://evil.example/callback',
        ]);

        $response->assertStatus(422);
        $this->assertArrayHasKey('redirect_uri', (array) $response->json('data'));
        Http::assertNothingSent();
    }

    /**
     * Regression: TrustedX answers 400 on a redirect_uri mismatch or a replayed
     * code. Http::post() does not throw there, and the missing access_token used
     * to surface as an ErrorException instead of a login failure.
     */
    #[Test]
    public function client_login_fails_cleanly_when_trustedx_refuses_the_code(): void
    {
        Http::fake([
            '*/trustedx-authserver/oauth/*' => Http::response([
                'error' => 'invalid_grant',
                'error_description' => 'redirectUriMismatch',
            ], 400),
        ]);

        $result = app(UserRegistrationService::class)->login('auth-code', self::REDIRECT_URI);

        $this->assertFalse($result->success);
        $this->assertNotEmpty($result->message);
    }

    private function fakeTrustedX(): void
    {
        Http::fake([
            '*/trustedx-authserver/oauth/*' => Http::response([
                'access_token' => 'pki-access-token',
                'token_type' => 'Bearer',
            ], 200),
            '*/trustedx-resources/openid/v1/users/me' => Http::response([
                'npi' => '9876543210123',
                'first_name' => 'Marc',
                'last_name' => 'Aurele',
                'name' => 'DOSSOU',
                'sub' => 'tx-subject-id',
            ], 200),
        ]);
    }
}
