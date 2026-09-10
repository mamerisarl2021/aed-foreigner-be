<?php

declare(strict_types=1);

namespace Tests\Feature\Enrollment;

use App\Enums\EnrollmentStatus;
use App\Models\EnrollmentRequest;
use App\Services\Enrollment\EnrollmentSimilarityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class EnrollmentSimilarityCacheTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function a_new_lookalike_dossier_is_visible_before_the_cache_ttl(): void
    {
        config(['enrollment.similarity.cache_ttl_seconds' => 120]);

        $needle = [
            'name' => 'SIMILARNOM',
            'first_name' => 'SIMILARPRENOM',
            'date_of_birth' => '1990-01-01',
            'nationality' => 'FR',
            'document_number' => 'X111111',
        ];

        $first = EnrollmentRequest::query()->create([
            'email' => 'similar-a@example.com',
            'phonenumber' => '+2290162405401',
            'status' => EnrollmentStatus::EnAttenteAgent->value,
            'type' => 'PERSONNE_PHYSIQUE',
            'kyc_data' => $needle,
        ]);
        $second = EnrollmentRequest::query()->create([
            'email' => 'similar-b@example.com',
            'phonenumber' => '+2290162405402',
            'status' => EnrollmentStatus::EnAttenteAgent->value,
            'type' => 'PERSONNE_PHYSIQUE',
            'kyc_data' => $needle,
        ]);

        $service = app(EnrollmentSimilarityService::class);
        $ids = collect($service->findSimilar($first))->pluck('enrollment_request_id')->all();
        $this->assertContains($second->id, $ids);

        $third = EnrollmentRequest::query()->create([
            'email' => 'similar-c@example.com',
            'phonenumber' => '+2290162405403',
            'status' => EnrollmentStatus::EnAttenteAgent->value,
            'type' => 'PERSONNE_PHYSIQUE',
            'kyc_data' => $needle,
        ]);

        $refreshed = collect($service->findSimilar($first))->pluck('enrollment_request_id')->all();
        $this->assertContains($third->id, $refreshed);
    }
}
