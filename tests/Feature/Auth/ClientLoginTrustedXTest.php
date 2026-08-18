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

        $result = app(UserRegistrationService::class)->login('auth-code');

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

        $result = app(UserRegistrationService::class)->login('auth-code');

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
