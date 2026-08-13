<?php

declare(strict_types=1);

namespace Tests\Unit\Http\Resources;

use App\Http\Resources\Concerns\FormatsEnrollmentDocuments;
use App\Http\Resources\Concerns\MapsEnrollmentKycAnalysis;
use App\Models\EnrollmentRequest;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class MapsEnrollmentKycAnalysisTest extends TestCase
{
    #[Test]
    public function it_shapes_analyse_kyc_for_the_backoffice_liveness_panel(): void
    {
        $enrollment = new EnrollmentRequest([
            'kyc_data' => [
                'name' => 'KOTO',
                'first_name' => 'Ada',
                'document_type' => 'Passeport',
                'document_number' => 'AB123456',
                'date_of_birth' => '2000-05-25',
                'nationality' => 'BJ',
                'country_of_residence' => 'Côte d\'Ivoire',
            ],
            'documents' => [
                'recto' => 'docs/recto.jpg',
                'selfie' => 'selfies/face.jpg',
            ],
            'liveness' => '0',
            'similarity' => '0.70',
            'risk_score' => '3',
            'analysis_details' => [
                'doc_validity' => true,
                'face_match' => true,
            ],
        ]);

        $payload = (new KycAnalysisMapperHarness)->map($enrollment);

        $this->assertSame('0', $payload['liveness']);
        $this->assertSame('0.70', $payload['similarity']);
        $this->assertSame(70, $payload['similarity_percent']);
        $this->assertSame('3', $payload['risk_score']);
        $this->assertSame(['doc_validity' => true, 'face_match' => true], $payload['details']);

        $this->assertSame([
            'type_piece' => 'Passeport',
            'pays' => 'Côte d\'Ivoire',
            'verifie' => true,
            'numero_document' => 'AB123456',
            'nom' => 'KOTO',
            'prenoms' => 'Ada',
            'date_naissance' => '2000-05-25',
            'nationalite' => 'BJ',
            'date_expiration' => null,
        ], $payload['document_identite']);

        $this->assertSame('https://example.test/selfies/face.jpg', $payload['selfie']['url']);
        $this->assertNull($payload['selfie']['capture_le']);

        $this->assertSame([
            'document_ajoute' => true,
            'informations_extraites' => true,
            'liveness_effectue' => true,
            'visage_compare' => true,
        ], $payload['etapes']);
    }

    #[Test]
    public function it_treats_similarity_above_one_as_already_a_percent(): void
    {
        $enrollment = new EnrollmentRequest([
            'kyc_data' => [],
            'documents' => [],
            'similarity' => '85',
            'liveness' => null,
        ]);

        $payload = (new KycAnalysisMapperHarness)->map($enrollment);

        $this->assertSame(85, $payload['similarity_percent']);
        $this->assertFalse($payload['etapes']['document_ajoute']);
        $this->assertFalse($payload['etapes']['liveness_effectue']);
        $this->assertTrue($payload['etapes']['visage_compare']);
    }

    #[Test]
    public function it_marks_failed_liveness_as_not_effectue(): void
    {
        $enrollment = new EnrollmentRequest([
            'kyc_data' => ['name' => 'X'],
            'documents' => ['recto' => 'r.jpg'],
            'liveness' => 'liveness_not_confirmed',
            'similarity' => null,
        ]);

        $payload = (new KycAnalysisMapperHarness)->map($enrollment);

        $this->assertFalse($payload['etapes']['liveness_effectue']);
        $this->assertFalse($payload['etapes']['visage_compare']);
        $this->assertNull($payload['similarity_percent']);
        $this->assertNull($payload['selfie']['url']);
    }
}

/**
 * Exposes the trait for unit tests without hitting cloud storage.
 */
final class KycAnalysisMapperHarness
{
    use FormatsEnrollmentDocuments;
    use MapsEnrollmentKycAnalysis;

    public function map(EnrollmentRequest $enrollment): array
    {
        return $this->analyseKycPhysique($enrollment);
    }

    protected function documentUrl(?array $docs, string $key): ?string
    {
        if (! is_array($docs) || empty($docs[$key])) {
            return null;
        }

        return 'https://example.test/'.$docs[$key];
    }
}
