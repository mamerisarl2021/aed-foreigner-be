<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Models\User;
use App\Services\PKI\TrustedXClientService;
use App\Support\ClientLocalCredentials;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class ClientSecurityTest extends TestCase
{
    use RefreshDatabase;

    private User $client;

    private User $agent;

    protected function setUp(): void
    {
        parent::setUp();

        foreach ([config('roles.client'), config('roles.agent')] as $role) {
            Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        }

        $this->client = User::factory()->create([
            'email' => 'client-security@example.com',
            'npi' => '9876543210987',
            'status' => 'ACTIVE',
            'password' => 'OldSecret1!',
            'pin_hash' => '1234',
            'security_questions' => [
                ['question' => 'Ville de naissance ?', 'answer' => 'secret-answer'],
                ['question' => 'Premier animal ?', 'answer' => 'chat'],
            ],
        ]);
        $this->client->assignRole(config('roles.client'));

        $this->agent = User::factory()->create([
            'email' => 'agent-security@example.com',
            'status' => 'ACTIVE',
        ]);
        $this->agent->assignRole(config('roles.agent'));
    }

    #[Test]
    public function the_client_can_change_password_with_the_current_secret(): void
    {
        $this->mockTrustedXPasswordPush();
        Sanctum::actingAs($this->client);

        $this->postJson($this->api('/clients/password/change'), [
            'current_password' => 'OldSecret1!',
            'password' => 'NewSecret1!',
            'password_confirmation' => 'NewSecret1!',
        ])
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->client->refresh();
        $this->assertTrue(Hash::check('NewSecret1!', $this->client->password));
        $this->assertSame(0, $this->client->tokens()->count());
    }

    #[Test]
    public function password_change_rejects_a_wrong_current_password(): void
    {
        Sanctum::actingAs($this->client);

        $this->postJson($this->api('/clients/password/change'), [
            'current_password' => 'WrongSecret1!',
            'password' => 'NewSecret1!',
            'password_confirmation' => 'NewSecret1!',
        ])
            ->assertStatus(400)
            ->assertJsonPath('message', 'Mot de passe actuel incorrect.');
    }

    #[Test]
    public function password_change_requires_email_reset_when_the_local_hash_is_missing(): void
    {
        $this->client->forceFill(['password' => null])->save();
        Sanctum::actingAs($this->client);

        $this->postJson($this->api('/clients/password/change'), [
            'current_password' => 'anything1',
            'password' => 'NewSecret1!',
            'password_confirmation' => 'NewSecret1!',
        ])
            ->assertStatus(422)
            ->assertJsonPath('message', ClientLocalCredentials::MISSING_HASH_MESSAGE);
    }

    #[Test]
    public function the_client_can_change_pin_with_the_current_pin(): void
    {
        $this->mockTrustedXPasswordPush();
        Sanctum::actingAs($this->client);

        $this->postJson($this->api('/clients/pin/change'), [
            'current_pin' => '1234',
            'pin' => '9876',
            'pin_confirmation' => '9876',
        ])
            ->assertOk();

        $this->client->refresh();
        $this->assertTrue(Hash::check('9876', (string) $this->client->pin_hash));
    }

    #[Test]
    public function pin_change_rejects_a_wrong_current_pin(): void
    {
        Sanctum::actingAs($this->client);

        $this->postJson($this->api('/clients/pin/change'), [
            'current_pin' => '0000',
            'pin' => '9876',
            'pin_confirmation' => '9876',
        ])
            ->assertStatus(400)
            ->assertJsonPath('message', 'PIN actuel incorrect.');
    }

    #[Test]
    public function security_questions_are_listed_without_answers(): void
    {
        Sanctum::actingAs($this->client);

        $this->getJson($this->api('/clients/security-questions'))
            ->assertOk()
            ->assertJsonPath('data.configure', true)
            ->assertJsonPath('data.questions.0.question', 'Ville de naissance ?')
            ->assertJsonMissingPath('data.questions.0.answer')
            ->assertJsonMissing(['data' => ['questions' => [['answer' => 'secret-answer']]]]);
    }

    #[Test]
    public function security_questions_update_requires_the_current_password(): void
    {
        Sanctum::actingAs($this->client);

        $payload = [
            'security_questions' => [
                ['question' => 'Couleur préférée ?', 'answer' => 'bleu'],
                ['question' => 'École ?', 'answer' => 'lycee'],
            ],
        ];

        $this->putJson($this->api('/clients/security-questions'), $payload + [
            'current_password' => 'WrongSecret1!',
        ])
            ->assertStatus(400);

        $this->putJson($this->api('/clients/security-questions'), $payload + [
            'current_password' => 'OldSecret1!',
        ])
            ->assertOk()
            ->assertJsonPath('data.questions.0.question', 'Couleur préférée ?')
            ->assertJsonMissingPath('data.questions.0.answer');

        $this->client->refresh();
        $this->assertSame('bleu', $this->client->security_questions[0]['answer'] ?? null);
    }

    #[Test]
    public function staff_cannot_change_client_credentials(): void
    {
        Sanctum::actingAs($this->agent);

        $this->postJson($this->api('/clients/password/change'), [
            'current_password' => 'password',
            'password' => 'NewSecret1!',
            'password_confirmation' => 'NewSecret1!',
        ])->assertForbidden();

        $this->getJson($this->api('/clients/security-questions'))->assertForbidden();
    }

    private function mockTrustedXPasswordPush(): void
    {
        $this->mock(TrustedXClientService::class, function ($mock) {
            $mock->shouldReceive('getUserWithNPI')
                ->once()
                ->andReturn(['status' => true, 'data' => ['id' => 'tx-user-1']]);
            $mock->shouldReceive('setDefaultPassword')
                ->once()
                ->andReturn(['status' => true, 'data' => []]);
        });
    }
}
