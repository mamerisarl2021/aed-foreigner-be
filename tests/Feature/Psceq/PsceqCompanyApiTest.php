<?php

declare(strict_types=1);

namespace Tests\Feature\Psceq;

use App\Enums\ActivityLogAction;
use App\Enums\EnrolledCompanyStatus;
use App\Enums\EnrollmentStatus;
use App\Models\ActivityLog;
use App\Models\EnrolledCompany;
use App\Models\EnrollmentRequest;
use App\Models\PsceqClient;
use App\Models\User;
use App\Support\PsceqApiKey;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class PsceqCompanyApiTest extends TestCase
{
    use RefreshDatabase;

    private string $apiKey;

    private PsceqClient $client;

    private EnrolledCompany $company;

    protected function setUp(): void
    {
        parent::setUp();

        Role::firstOrCreate([
            'name' => config('roles.administrateur_plateforme'),
            'guard_name' => 'web',
        ]);

        [$this->client, $this->apiKey] = $this->issueKey();
        $this->company = $this->seedCompany();
    }

    #[Test]
    public function missing_unknown_and_revoked_keys_return_the_same_401(): void
    {
        $this->getJson($this->api('/psceq/entreprises?q=TECH'))
            ->assertUnauthorized()
            ->assertJsonPath('message', 'Clé API invalide.');

        $this->withToken(PsceqApiKey::PREFIX.str_repeat('ab', 32))
            ->getJson($this->api('/psceq/entreprises?q=TECH'))
            ->assertUnauthorized()
            ->assertJsonPath('message', 'Clé API invalide.');

        $this->client->forceFill(['revoked_at' => now()])->save();

        $this->withToken($this->apiKey)
            ->getJson($this->api('/psceq/entreprises?q=TECH'))
            ->assertUnauthorized()
            ->assertJsonPath('message', 'Clé API invalide.');
    }

    #[Test]
    public function valid_bearer_and_x_api_key_are_accepted(): void
    {
        $this->withToken($this->apiKey)
            ->getJson($this->api('/psceq/entreprises?q=TECH'))
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->withHeaders(['X-Api-Key' => $this->apiKey])
            ->getJson($this->api('/psceq/entreprises?q=TECH'))
            ->assertOk()
            ->assertJsonPath('success', true);
    }

    #[Test]
    public function search_returns_short_payload_without_email_or_documents(): void
    {
        $response = $this->withToken($this->apiKey)
            ->getJson($this->api('/psceq/entreprises?q=tech'))
            ->assertOk()
            ->assertJsonPath('data.0.identifiant', 'PMTEST00001')
            ->assertJsonPath('data.0.raison_sociale', 'TECH SARL INNOV')
            ->assertJsonPath('data.0.pays_origine', 'Canada')
            ->assertJsonPath('data.0.statut', EnrolledCompanyStatus::Active->value);

        $payload = (string) $response->getContent();
        $this->assertStringNotContainsString('secret-psceq@example.com', $payload);
        $this->assertStringNotContainsString('demande_id', $payload);
        $this->assertStringNotContainsString($this->company->id, $payload);
        $this->assertStringNotContainsString('documents', $payload);

        $this->assertTrue(ActivityLog::query()
            ->where('action_code', ActivityLogAction::PsceqConsultation->label())
            ->whereNull('actor_user_id')
            ->exists());
    }

    #[Test]
    public function search_matches_legal_name_after_upper_and_trim(): void
    {
        $manager = User::factory()->create(['email' => 'manager-padded@example.com']);
        $enrollment = EnrollmentRequest::query()->create([
            'tracking_code' => 'PKPSCEQ002',
            'email' => 'padded-psceq@example.com',
            'phonenumber' => '+2290162405473',
            'status' => EnrollmentStatus::Approuvee->value,
            'type' => 'PERSONNE_MORALE',
            'submitted_by_user_id' => $manager->id,
            'kyc_data' => ['legal_name' => '  padded sarl  '],
        ]);

        EnrolledCompany::query()->create([
            'identifiant' => 'PMTESTPAD01',
            'enrollment_request_id' => $enrollment->id,
            'manager_user_id' => $manager->id,
            'legal_name' => '  padded sarl  ',
            'legal_form' => 'SARL',
            'country_of_incorporation' => 'Benin',
            'registration_number' => 'RCCM-PSCEQ-PAD',
            'headquarters_address' => 'Cotonou',
            'activity_sector' => 'Services',
            'legal_representative_name' => 'KOTO',
            'legal_representative_first_name' => 'Ada',
            'company_email' => 'padded-psceq@example.com',
            'company_phone' => '+2290162405473',
            'documents' => ['statuts' => 'secret.pdf'],
            'status' => EnrolledCompany::STATUS_ACTIVE,
            'approved_at' => now(),
        ]);

        $this->withToken($this->apiKey)
            ->getJson($this->api('/psceq/entreprises?q=PADDED'))
            ->assertOk()
            ->assertJsonPath('data.0.identifiant', 'PMTESTPAD01')
            ->assertJsonPath('data.0.raison_sociale', '  padded sarl  ');
    }

    #[Test]
    public function search_requires_q_of_at_least_two_characters(): void
    {
        $this->withToken($this->apiKey)
            ->getJson($this->api('/psceq/entreprises'))
            ->assertStatus(422);

        $this->withToken($this->apiKey)
            ->getJson($this->api('/psceq/entreprises?q=T'))
            ->assertStatus(422);
    }

    #[Test]
    public function detail_administrateur_and_statut_expose_identity_only(): void
    {
        $identifiant = $this->company->identifiant;

        $detail = $this->withToken($this->apiKey)
            ->getJson($this->api("/psceq/entreprises/{$identifiant}"))
            ->assertOk()
            ->assertJsonPath('data.identifiant', $identifiant)
            ->assertJsonPath('data.raison_sociale', 'TECH SARL INNOV')
            ->assertJsonPath('data.statut', EnrolledCompanyStatus::Active->value);

        $detailBody = (string) $detail->getContent();
        $this->assertStringNotContainsString('secret-psceq@example.com', $detailBody);
        $this->assertStringNotContainsString('demande_id', $detailBody);
        $this->assertStringNotContainsString($this->company->id, $detailBody);

        $this->withToken($this->apiKey)
            ->getJson($this->api("/psceq/entreprises/{$identifiant}/administrateur"))
            ->assertOk()
            ->assertJsonPath('data.identifiant', $identifiant)
            ->assertJsonPath('data.nom', 'KOTO')
            ->assertJsonPath('data.prenoms', 'Ada');

        $this->withToken($this->apiKey)
            ->getJson($this->api("/psceq/entreprises/{$identifiant}/statut"))
            ->assertOk()
            ->assertJsonPath('data.identifiant', $identifiant)
            ->assertJsonPath('data.statut', EnrolledCompanyStatus::Active->value)
            ->assertJsonPath('data.existe', true);
    }

    #[Test]
    public function unknown_or_malformed_identifiant_returns_uniform_404(): void
    {
        $this->withToken($this->apiKey)
            ->getJson($this->api('/psceq/entreprises/PMNOEXIST01'))
            ->assertNotFound()
            ->assertJsonPath('message', 'Entreprise introuvable.');

        $this->withToken($this->apiKey)
            ->getJson($this->api('/psceq/entreprises/pas-un-id'))
            ->assertNotFound()
            ->assertJsonPath('message', 'Entreprise introuvable.');

        $this->withToken($this->apiKey)
            ->getJson($this->api('/psceq/entreprises/pas-un-id/statut'))
            ->assertNotFound()
            ->assertJsonPath('message', 'Entreprise introuvable.');
    }

    #[Test]
    public function patch_status_is_visible_on_psceq_statut(): void
    {
        $admin = User::factory()->create(['email' => 'admin-psceq-status@example.com']);
        $admin->assignRole(config('roles.administrateur_plateforme'));
        Sanctum::actingAs($admin);

        $this->patchJson($this->api("/admin/enrolled-companies/{$this->company->id}/status"), [
            'statut' => EnrolledCompanyStatus::Suspended->value,
        ])->assertOk();

        $this->withToken($this->apiKey)
            ->getJson($this->api("/psceq/entreprises/{$this->company->identifiant}/statut"))
            ->assertOk()
            ->assertJsonPath('data.statut', EnrolledCompanyStatus::Suspended->value)
            ->assertJsonPath('data.existe', true);
    }

    /**
     * @return array{0: PsceqClient, 1: string}
     */
    private function issueKey(): array
    {
        $plain = PsceqApiKey::generate();
        $client = PsceqClient::query()->create([
            'name' => 'PSCEQ Feature',
            'key_prefix' => PsceqApiKey::prefixOf($plain),
            'key_hash' => Hash::make($plain),
        ]);

        return [$client, $plain];
    }

    private function seedCompany(): EnrolledCompany
    {
        $manager = User::factory()->create(['email' => 'manager-psceq@example.com']);
        $enrollment = EnrollmentRequest::query()->create([
            'tracking_code' => 'PKPSCEQ001',
            'email' => 'secret-psceq@example.com',
            'phonenumber' => '+2290162405472',
            'status' => EnrollmentStatus::Approuvee->value,
            'type' => 'PERSONNE_MORALE',
            'submitted_by_user_id' => $manager->id,
            'kyc_data' => ['legal_name' => 'TECH SARL INNOV'],
        ]);

        return EnrolledCompany::query()->create([
            'identifiant' => 'PMTEST00001',
            'enrollment_request_id' => $enrollment->id,
            'manager_user_id' => $manager->id,
            'legal_name' => 'TECH SARL INNOV',
            'legal_form' => 'SARL',
            'country_of_incorporation' => 'Canada',
            'registration_number' => 'RCCM-PSCEQ-001',
            'headquarters_address' => 'Cotonou',
            'activity_sector' => 'Services',
            'legal_representative_name' => 'KOTO',
            'legal_representative_first_name' => 'Ada',
            'company_email' => 'secret-psceq@example.com',
            'company_phone' => '+2290162405472',
            'documents' => ['statuts' => 'secret.pdf'],
            'status' => EnrolledCompany::STATUS_ACTIVE,
            'approved_at' => now(),
        ]);
    }
}
