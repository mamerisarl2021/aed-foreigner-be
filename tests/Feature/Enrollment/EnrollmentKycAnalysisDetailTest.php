<?php

declare(strict_types=1);

namespace Tests\Feature\Enrollment;

use App\Enums\EnrollmentStatus;
use App\Models\EnrollmentRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class EnrollmentKycAnalysisDetailTest extends TestCase
{
    use RefreshDatabase;

    private User $agent;

    private User $responsable;

    protected function setUp(): void
    {
        parent::setUp();

        config(['filesystems.cloud' => 's3']);
        Storage::fake('s3');
        Storage::disk('s3')->put('selfies/face.jpg', 'selfie');
        Storage::disk('s3')->put('docs/recto.jpg', 'recto');

        foreach ([config('roles.agent'), config('roles.responsable_de_validation')] as $role) {
            Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        }

        $this->agent = User::factory()->create(['email' => 'agent-kyc-detail@example.com']);
        $this->agent->assignRole(config('roles.agent'));

        $this->responsable = User::factory()->create(['email' => 'responsable-kyc-detail@example.com']);
        $this->responsable->assignRole(config('roles.responsable_de_validation'));
    }

    #[Test]
    public function the_agent_detail_exposes_the_liveness_panel_shape(): void
    {
        $enrollment = $this->createPhysiqueEnrollment([
            'liveness' => '0',
            'similarity' => '0.70',
            'risk_score' => '3',
            'analysis_details' => [
                'doc_validity' => true,
                'face_match' => true,
                'document' => [
                    'document_name' => 'Côte d\'Ivoire - Passport',
                    'ocr' => [
                        'nom' => 'KOUASSI',
                        'prenoms' => 'YAO',
                        'numero_piece' => 'CI999',
                        'sexe' => 'M',
                        'date_emission' => '2016-10-17',
                    ],
                ],
            ],
        ]);

        Sanctum::actingAs($this->agent);

        $this->getJson($this->api("/enrolements/{$enrollment->id}"))
            ->assertOk()
            ->assertJsonPath('data.analyse_kyc.similarity_percent', 70)
            ->assertJsonPath('data.analyse_kyc.document_identite.nom', 'KOUASSI')
            ->assertJsonPath('data.analyse_kyc.document_identite.pays', 'Côte d\'Ivoire')
            ->assertJsonPath('data.analyse_kyc.document_identite.verifie', true)
            ->assertJsonPath('data.analyse_kyc.document_identite.sexe', 'M')
            ->assertJsonPath('data.analyse_kyc.document_identite.date_emission', '2016-10-17')
            ->assertJsonPath('data.analyse_kyc.etapes.liveness_effectue', true)
            ->assertJsonPath('data.analyse_kyc.etapes.visage_compare', true)
            ->assertJsonPath('data.analyse_kyc.etapes.document_ajoute', true)
            ->assertJsonPath('data.analyse_kyc.selfie.capture_le', null);

        $selfieUrl = $this->getJson($this->api("/enrolements/{$enrollment->id}"))->json('data.analyse_kyc.selfie.url');
        $this->assertIsString($selfieUrl);
        $this->assertNotSame('', $selfieUrl);
    }

    #[Test]
    public function failed_liveness_is_not_shown_as_effectue_on_agent_or_responsable_detail(): void
    {
        $enrollment = $this->createPhysiqueEnrollment([
            'status' => EnrollmentStatus::EnAttenteResponsable->value,
            'assigned_agent_id' => $this->agent->id,
            'liveness' => '1',
            'similarity' => '0.40',
            'analysis_details' => [
                'error' => 'liveness_not_confirmed',
                'doc_validity' => true,
                'document' => ['ocr' => ['nom' => 'KOUASSI']],
            ],
        ]);

        Sanctum::actingAs($this->agent);
        $this->getJson($this->api("/enrolements/{$enrollment->id}"))
            ->assertOk()
            ->assertJsonPath('data.analyse_kyc.etapes.liveness_effectue', false)
            ->assertJsonPath('data.analyse_kyc.document_identite.verifie', false)
            ->assertJsonPath('data.analyse_kyc.etapes.visage_compare', true);

        Sanctum::actingAs($this->responsable);
        $this->getJson($this->api("/enrolements/{$enrollment->id}"))
            ->assertOk()
            ->assertJsonPath('data.analyse_kyc.etapes.liveness_effectue', false)
            ->assertJsonPath('data.analyse_kyc.document_identite.verifie', false);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function createPhysiqueEnrollment(array $attributes = []): EnrollmentRequest
    {
        return EnrollmentRequest::query()->create(array_merge([
            'email' => 'applicant-kyc-detail@example.com',
            'phonenumber' => '+2290162405472',
            'status' => EnrollmentStatus::EnAttenteAgent->value,
            'type' => 'PERSONNE_PHYSIQUE',
            'kyc_data' => ['name' => 'SAISI', 'first_name' => 'Form'],
            'documents' => [
                'recto' => 'docs/recto.jpg',
                'selfie' => 'selfies/face.jpg',
            ],
        ], $attributes));
    }
}
