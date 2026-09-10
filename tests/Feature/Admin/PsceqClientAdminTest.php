<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Enums\ActivityLogAction;
use App\Enums\EnrolledCompanyStatus;
use App\Enums\EnrollmentStatus;
use App\Models\ActivityLog;
use App\Models\EnrolledCompany;
use App\Models\EnrollmentRequest;
use App\Models\PsceqClient;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class PsceqClientAdminTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $agent;

    protected function setUp(): void
    {
        parent::setUp();

        foreach ([
            config('roles.administrateur_plateforme'),
            config('roles.agent'),
        ] as $role) {
            Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        }

        $this->admin = User::factory()->create(['email' => 'admin-psceq@example.com']);
        $this->admin->assignRole(config('roles.administrateur_plateforme'));

        $this->agent = User::factory()->create(['email' => 'agent-psceq@example.com']);
        $this->agent->assignRole(config('roles.agent'));
    }

    #[Test]
    public function admin_issues_key_once_then_list_omits_secret_and_hash(): void
    {
        Sanctum::actingAs($this->admin);

        $create = $this->postJson($this->api('/admin/psceq-clients'), $this->prestatairePayload('Prestataire Test'));

        $create->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.nom', 'Prestataire Test')
            ->assertJsonPath('data.raison_sociale', 'Prestataire Test SARL')
            ->assertJsonPath('data.revoque', false);

        $plain = $create->json('data.api_key');
        $id = $create->json('data.id');
        $this->assertIsString($plain);
        $this->assertNotEmpty($plain);
        $this->assertStringStartsWith('psceq_', $plain);
        $this->assertIsString($id);

        $this->assertDatabaseHas('activity_logs', [
            'action_code' => ActivityLogAction::PsceqClientCree->label(),
        ]);

        $list = $this->getJson($this->api('/admin/psceq-clients'));
        $list->assertOk();

        $payload = (string) $list->getContent();
        $this->assertStringNotContainsString($plain, $payload);
        $this->assertStringNotContainsString('key_hash', $payload);
        $this->assertStringNotContainsString('"api_key"', $payload);

        $row = collect($list->json('data.data'))->firstWhere('id', $id);
        $this->assertIsArray($row);
        $this->assertArrayNotHasKey('api_key', $row);
        $this->assertArrayNotHasKey('key_hash', $row);
        $this->assertSame('Prestataire Test', $row['nom']);
    }

    #[Test]
    public function admin_can_revoke_key_idempotently(): void
    {
        Sanctum::actingAs($this->admin);

        $id = $this->postJson($this->api('/admin/psceq-clients'), $this->prestatairePayload('À révoquer', 'revoke'))
            ->assertCreated()
            ->json('data.id');

        $this->postJson($this->api("/admin/psceq-clients/{$id}/revoke"))
            ->assertOk()
            ->assertJsonPath('data.revoque', true);

        $this->postJson($this->api("/admin/psceq-clients/{$id}/revoke"))
            ->assertOk()
            ->assertJsonPath('data.revoque', true);

        $this->assertNotNull(PsceqClient::query()->findOrFail($id)->revoked_at);
        $this->assertSame(1, ActivityLog::query()
            ->where('action_code', ActivityLogAction::PsceqClientRevoque->label())
            ->count());
        $this->assertSame(0, ActivityLog::query()
            ->where('action_code', ActivityLogAction::PsceqClientReactive->label())
            ->count());
    }

    #[Test]
    public function agent_cannot_create_or_list_psceq_clients(): void
    {
        Sanctum::actingAs($this->agent);

        $this->getJson($this->api('/admin/psceq-clients'))->assertForbidden();
        $this->postJson($this->api('/admin/psceq-clients'), ['nom' => 'Non'])->assertForbidden();
        $this->postJson($this->api('/admin/psceq-clients'), $this->prestatairePayload('Non'))->assertForbidden();
    }

    #[Test]
    public function admin_can_suspend_enrolled_company(): void
    {
        Sanctum::actingAs($this->admin);
        $company = $this->seedCompany();

        $this->patchJson($this->api("/admin/enrolled-companies/{$company->id}/status"), [
            'statut' => EnrolledCompanyStatus::Suspended->value,
        ])
            ->assertOk()
            ->assertJsonPath('success', true);

        $company->refresh();
        $this->assertSame(EnrolledCompanyStatus::Suspended, $company->status);

        $this->assertDatabaseHas('activity_logs', [
            'action_code' => ActivityLogAction::EntrepriseStatutModifie->label(),
        ]);
    }

    #[Test]
    public function create_rejects_nom_without_the_prestataire_profile(): void
    {
        Sanctum::actingAs($this->admin);

        $this->postJson($this->api('/admin/psceq-clients'), ['nom' => 'Incomplet'])
            ->assertStatus(422);
    }

    #[Test]
    public function admin_can_show_update_regenerate_and_read_historique(): void
    {
        Sanctum::actingAs($this->admin);

        $id = $this->postJson($this->api('/admin/psceq-clients'), $this->prestatairePayload('Historique Co', 'hist'))
            ->assertCreated()
            ->json('data.id');
        $this->assertIsString($id);

        $this->getJson($this->api("/admin/psceq-clients/{$id}"))
            ->assertOk()
            ->assertJsonPath('data.nom', 'Historique Co')
            ->assertJsonMissing(['api_key']);

        $updated = $this->prestatairePayload('Historique Co', 'hist');
        $updated['nom'] = 'Historique Co Modifié';
        $this->putJson($this->api("/admin/psceq-clients/{$id}"), $updated)
            ->assertOk()
            ->assertJsonPath('data.nom', 'Historique Co Modifié');

        $regen = $this->postJson($this->api("/admin/psceq-clients/{$id}/regenerate"));
        $regen->assertOk();
        $this->assertIsString($regen->json('data.api_key'));
        $this->assertStringStartsWith('psceq_', (string) $regen->json('data.api_key'));

        $this->getJson($this->api("/admin/psceq-clients/{$id}/historique"))
            ->assertOk()
            ->assertJsonPath('success', true);
    }

    /**
     * @return array<string, string>
     */
    private function prestatairePayload(string $nom, string $suffix = 'create'): array
    {
        return [
            'nom' => $nom,
            'raison_sociale' => $nom.' SARL',
            'rccm' => 'RCCM-'.$suffix,
            'pays' => 'Bénin',
            'adresse_siege' => 'Cotonou',
            'email' => "psceq-{$suffix}@example.com",
            'telephone' => '+2290162405472',
            'point_focal_nom' => 'KOTO',
            'point_focal_prenom' => 'Ada',
            'point_focal_fonction' => 'Directrice',
            'point_focal_email' => "focal-{$suffix}@example.com",
            'point_focal_telephone' => '+2290162405473',
        ];
    }

    private function seedCompany(): EnrolledCompany
    {
        $manager = User::factory()->create(['email' => 'manager-psceq-admin@example.com']);
        $enrollment = EnrollmentRequest::query()->create([
            'tracking_code' => 'PKPSCEQADM',
            'email' => 'societe-admin@example.com',
            'phonenumber' => '+2290162405472',
            'status' => EnrollmentStatus::Approuvee->value,
            'type' => 'PERSONNE_MORALE',
            'submitted_by_user_id' => $manager->id,
            'kyc_data' => ['legal_name' => 'ADMIN SARL'],
        ]);

        return EnrolledCompany::query()->create([
            'identifiant' => 'PMADMIN0001',
            'enrollment_request_id' => $enrollment->id,
            'manager_user_id' => $manager->id,
            'legal_name' => 'ADMIN SARL',
            'country_of_incorporation' => 'Canada',
            'registration_number' => 'RCCM-ADM-001',
            'headquarters_address' => 'Cotonou',
            'activity_sector' => 'Services',
            'legal_representative_name' => 'KOTO',
            'legal_representative_first_name' => 'Ada',
            'company_email' => 'societe-admin@example.com',
            'status' => EnrolledCompany::STATUS_ACTIVE,
            'approved_at' => now(),
        ]);
    }
}
