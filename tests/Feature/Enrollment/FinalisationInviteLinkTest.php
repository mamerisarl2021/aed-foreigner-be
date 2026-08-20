<?php

declare(strict_types=1);

namespace Tests\Feature\Enrollment;

use App\Enums\AgentAvis;
use App\Enums\EnrollmentStatus;
use App\Enums\NotificationTemplate;
use App\Jobs\Notifications\SendEmailNotificationJob;
use App\Jobs\WelcomeUserJob;
use App\Models\EnrollmentRequest;
use App\Models\PasswordResetToken;
use App\Models\User;
use App\Services\PKI\TrustedXClientService;
use App\Support\SecurityQuestions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class FinalisationInviteLinkTest extends TestCase
{
    use RefreshDatabase;

    private User $responsable;

    protected function setUp(): void
    {
        parent::setUp();

        Bus::fake([
            SendEmailNotificationJob::class,
            WelcomeUserJob::class,
        ]);
        config(['consul.keycloak.enabled' => false]);

        foreach ([
            config('roles.client'),
            config('roles.responsable_de_validation'),
        ] as $role) {
            Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        }

        $this->responsable = User::factory()->create(['email' => 'responsable-finalisation@example.com']);
        $this->responsable->assignRole(config('roles.responsable_de_validation'));
    }

    #[Test]
    public function approval_invite_link_uses_token_only_npi_stays_in_email_body(): void
    {
        $enrollment = EnrollmentRequest::query()->create([
            'tracking_code' => 'PKFINAL001',
            'email' => 'finaliser@example.com',
            'phonenumber' => '+2290162405472',
            'status' => EnrollmentStatus::EnCoursResponsable->value,
            'type' => 'PERSONNE_PHYSIQUE',
            'assigned_responsable_id' => $this->responsable->id,
            'agent_avis' => AgentAvis::Favorable->value,
            'kyc_data' => ['name' => 'KOTO', 'first_name' => 'Ada'],
        ]);

        $this->mock(TrustedXClientService::class, function ($mock): void {
            $mock->shouldReceive('register')->once()->andReturn(['status' => true]);
        });

        Sanctum::actingAs($this->responsable);

        $this->patchJson($this->api("/enrolements/{$enrollment->id}/validation"), [
            'decision' => 'APPROUVEE',
        ])->assertOk();

        $npi = User::query()->where('email', 'finaliser@example.com')->value('npi');
        $this->assertIsString($npi);
        $this->assertMatchesRegularExpression('/^1[0-9]{9}$/', $npi);

        Bus::assertDispatched(SendEmailNotificationJob::class, function (SendEmailNotificationJob $job) use ($npi, $enrollment): bool {
            if ($job->notification->template !== NotificationTemplate::SendInitLink) {
                return false;
            }

            $link = (string) ($job->notification->variables['link'] ?? '');

            return ($job->notification->variables['npi'] ?? null) === $npi
                && str_contains($link, 'token=')
                && ! str_contains($link, 'npi=')
                && ! str_contains($link, $enrollment->id)
                && ! str_contains($link, $enrollment->tracking_code);
        });

        Bus::assertDispatched(WelcomeUserJob::class, function (WelcomeUserJob $job) use ($enrollment): bool {
            return str_contains($job->activationUrl, 'token=')
                && ! str_contains($job->activationUrl, 'npi=')
                && ! str_contains($job->activationUrl, $enrollment->id);
        });
    }

    #[Test]
    public function finalisation_show_accepts_legacy_npi_query_but_does_not_echo_npi(): void
    {
        [$user, $enrollment, $plain] = $this->approvedInvite();

        $this->getJson($this->api('/enrolements/finalisation').'?'.http_build_query([
            'token' => $plain,
        ]))
            ->assertOk()
            ->assertJsonPath('data.demande_id', $enrollment->id)
            ->assertJsonPath('data.email', $user->email)
            ->assertJsonMissingPath('data.npi');

        $this->getJson($this->api('/enrolements/finalisation').'?'.http_build_query([
            'token' => $plain,
            'npi' => $user->npi,
        ]))
            ->assertOk()
            ->assertJsonPath('data.demande_id', $enrollment->id);

        $this->getJson($this->api('/enrolements/finalisation').'?'.http_build_query([
            'token' => $plain,
            'npi' => '199999999',
        ]))
            ->assertNotFound()
            ->assertJsonPath('message', 'Lien de finalisation invalide.');
    }

    #[Test]
    public function otp_requires_typed_npi_matching_the_token_not_numero_suivi(): void
    {
        [$user, $enrollment, $plain] = $this->approvedInvite();

        $this->postJson($this->api('/enrolements/finalisation/otp/send'), [
            'numero_suivi' => $enrollment->tracking_code,
        ])->assertStatus(422);

        $this->postJson($this->api('/enrolements/finalisation/otp/send'), [
            'npi' => $user->npi,
        ])->assertStatus(422);

        $this->postJson($this->api('/enrolements/finalisation/otp/send'), [
            'npi' => $enrollment->tracking_code,
            'token' => $plain,
        ])->assertStatus(422);

        $this->postJson($this->api('/enrolements/finalisation/otp/send'), [
            'npi' => $user->npi,
            'token' => $plain,
        ])
            ->assertOk()
            ->assertJsonPath('data.npi', $user->npi)
            ->assertJsonMissingPath('data.numero_suivi');

        $otp = null;
        Bus::assertDispatched(SendEmailNotificationJob::class, function (SendEmailNotificationJob $job) use (&$otp): bool {
            if ($job->notification->type !== 'FINALISATION_OTP_SEND') {
                return false;
            }
            $otp = $job->notification->variables['otp'] ?? null;

            return is_string($otp) && strlen($otp) === 6;
        });

        $this->postJson($this->api('/enrolements/finalisation/otp/verify'), [
            'npi' => $user->npi,
            'token' => $plain,
            'otp' => $otp,
        ])
            ->assertOk()
            ->assertJsonPath('data.otp_verified', true)
            ->assertJsonPath('data.npi', $user->npi);
    }

    #[Test]
    public function applicant_finalizes_with_npi_and_token(): void
    {
        [$user, $enrollment, $plain] = $this->approvedInvite();
        Cache::put('finalisation_otp_verified_'.$user->npi, true, now()->addMinutes(15));

        $this->mock(TrustedXClientService::class, function ($mock): void {
            $mock->shouldReceive('getUserWithNPI')
                ->once()
                ->andReturn(['status' => true, 'data' => ['id' => 'tx-final-1']]);
            $mock->shouldReceive('setDefaultPassword')
                ->twice()
                ->andReturn(['status' => true, 'data' => []]);
        });

        $this->postJson($this->api('/enrolements/finalisation'), [
            'npi' => $user->npi,
            'token' => $plain,
            'password' => 'SecretPass1',
            'security_questions' => [
                ['question' => 'Ville de naissance ?', 'answer' => 'Cotonou'],
                ['question' => 'Nom de jeune fille de la mère ?', 'answer' => 'Koto'],
            ],
        ])
            ->assertOk()
            ->assertJsonPath('message', 'Enrôlement finalisé.')
            ->assertJsonPath('data.statut', EnrollmentStatus::Enrolee->value)
            ->assertJsonPath('data.npi', $user->npi)
            ->assertJsonPath('data.demande_id', $enrollment->id);

        $this->assertDatabaseMissing('password_resets', [
            'npi' => $user->npi,
            'type' => 'finalisation',
        ]);
        $this->assertSame('ACTIVE', $user->fresh()->status);
        $this->assertSame(EnrollmentStatus::Enrolee, $enrollment->fresh()->status);

        $stored = $user->fresh()->security_questions;
        $this->assertIsArray($stored);
        $this->assertArrayNotHasKey('answer', $stored[0]);
        $this->assertTrue(SecurityQuestions::answerMatches('Cotonou', (string) ($stored[0]['answer_hash'] ?? '')));
        $encoded = json_encode($stored);
        $this->assertIsString($encoded);
        $this->assertStringNotContainsString('Cotonou', $encoded);
    }

    #[Test]
    public function finalisation_otp_send_is_not_blocked_by_an_existing_session(): void
    {
        [$user, , $plain] = $this->approvedInvite();
        $spectator = User::factory()->create(['email' => 'spectator-finalisation@example.com']);
        Sanctum::actingAs($spectator);

        $this->postJson($this->api('/enrolements/finalisation/otp/send'), [
            'npi' => $user->npi,
            'token' => $plain,
        ])
            ->assertOk()
            ->assertJsonPath('data.npi', $user->npi);
    }

    #[Test]
    public function finalize_rejects_numero_suivi_in_place_of_npi(): void
    {
        [$user, $enrollment, $plain] = $this->approvedInvite();
        Cache::put('finalisation_otp_verified_'.$user->npi, true, now()->addMinutes(15));

        $this->postJson($this->api('/enrolements/'.$enrollment->id.'/finalisation'), [
            'numero_suivi' => $enrollment->tracking_code,
            'password' => 'SecretPass1',
            'security_questions' => [
                ['question' => 'Q1', 'answer' => 'A1'],
                ['question' => 'Q2', 'answer' => 'A2'],
            ],
        ])->assertNotFound();

        $this->postJson($this->api('/enrolements/finalisation'), [
            'npi' => $enrollment->tracking_code,
            'token' => $plain,
            'password' => 'SecretPass1',
            'security_questions' => [
                ['question' => 'Q1', 'answer' => 'A1'],
                ['question' => 'Q2', 'answer' => 'A2'],
            ],
        ])->assertStatus(422);
    }

    /**
     * @return array{0: User, 1: EnrollmentRequest, 2: string}
     */
    private function approvedInvite(): array
    {
        $user = User::factory()->create([
            'email' => 'finaliser-show@example.com',
            'npi' => '100000042',
            'status' => 'CREATED',
            'trustedx_registered_at' => now(),
        ]);
        $enrollment = EnrollmentRequest::query()->create([
            'tracking_code' => 'PKFINAL002',
            'email' => $user->email,
            'phonenumber' => '+2290162405472',
            'status' => EnrollmentStatus::Approuvee->value,
            'type' => 'PERSONNE_PHYSIQUE',
            'kyc_data' => ['name' => 'KOTO'],
        ]);

        $plain = 'plain-finalisation-token';
        PasswordResetToken::query()->create([
            'npi' => $user->npi,
            'type' => 'finalisation',
            'token' => hash('sha256', $plain),
            'created_at' => now(),
        ]);

        return [$user, $enrollment, $plain];
    }
}
