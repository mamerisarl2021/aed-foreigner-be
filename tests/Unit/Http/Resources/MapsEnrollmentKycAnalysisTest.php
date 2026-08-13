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
        $this->assertTrue($payload['document_identite']['verifie']);
        $this->assertSame('AB123456', $payload['document_identite']['numero_document']);
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
    public function it_prefers_regula_ocr_over_declared_kyc_and_parses_document_name(): void
    {
        $enrollment = new EnrollmentRequest([
            'kyc_data' => [
                'name' => 'SAISI',
                'document_number' => 'DECLARE',
                'country_of_residence' => 'France',
            ],
            'documents' => ['recto' => 'r.jpg'],
            'liveness' => '0',
            'similarity' => '0.9',
            'analysis_details' => [
                'doc_validity' => true,
                'document' => [
                    'document_name' => 'Côte d\'Ivoire - Passport',
                    'ocr' => [
                        'nom' => 'KOUASSI',
                        'prenoms' => 'YAO',
                        'numero_piece' => 'CI999',
                        'date_naissance' => '1999-10-17',
                        'nationalite' => 'CIV',
                        'date_expiration' => '2026-10-17',
                    ],
                ],
            ],
        ]);

        $payload = (new KycAnalysisMapperHarness)->map($enrollment);

        $this->assertSame([
            'type_piece' => 'Passport',
            'pays' => 'Côte d\'Ivoire',
            'verifie' => true,
            'numero_document' => 'CI999',
            'nom' => 'KOUASSI',
            'prenoms' => 'YAO',
            'date_naissance' => '1999-10-17',
            'nationalite' => 'CIV',
            'date_expiration' => '2026-10-17',
        ], $payload['document_identite']);
    }

    #[Test]
    public function it_does_not_treat_a_document_summary_as_verified_when_analysis_failed(): void
    {
        $enrollment = new EnrollmentRequest([
            'kyc_data' => ['name' => 'X'],
            'documents' => ['recto' => 'r.jpg'],
            'liveness' => '1',
            'similarity' => '0.4',
            'analysis_details' => [
                'error' => 'liveness_not_confirmed',
                'doc_validity' => true,
                'document' => ['ocr' => ['nom' => 'X']],
            ],
        ]);

        $payload = (new KycAnalysisMapperHarness)->map($enrollment);

        $this->assertFalse($payload['document_identite']['verifie']);
        $this->assertFalse($payload['etapes']['liveness_effectue']);
        $this->assertTrue($payload['etapes']['visage_compare']);
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
    public function skipped_or_failed_liveness_is_not_effectue(): void
    {
        foreach (['1', 'skipped', 'liveness_not_confirmed', 'failed', null, ''] as $liveness) {
            $enrollment = new EnrollmentRequest([
                'kyc_data' => ['name' => 'X'],
                'documents' => ['recto' => 'r.jpg'],
                'liveness' => $liveness,
                'similarity' => null,
            ]);

            $payload = (new KycAnalysisMapperHarness)->map($enrollment);

            $this->assertFalse($payload['etapes']['liveness_effectue'], 'liveness='.var_export($liveness, true));
        }
    }

    #[Test]
    public function it_reads_ocr_from_the_mock_ocr_data_bag(): void
    {
        $enrollment = new EnrollmentRequest([
            'kyc_data' => ['name' => 'SAISI', 'document_number' => 'DECLARE'],
            'documents' => ['recto' => 'r.jpg'],
            'liveness' => '0',
            'analysis_details' => [
                'doc_validity' => true,
                'ocr_data' => [
                    'nom' => 'MOCK',
                    'numero_piece' => 'MOCK-1',
                ],
            ],
        ]);

        $payload = (new KycAnalysisMapperHarness)->map($enrollment);

        $this->assertSame('MOCK', $payload['document_identite']['nom']);
        $this->assertSame('MOCK-1', $payload['document_identite']['numero_document']);
        $this->assertTrue($payload['document_identite']['verifie']);
    }

    #[Test]
    public function it_does_not_mark_the_document_verified_without_doc_validity(): void
    {
        $enrollment = new EnrollmentRequest([
            'kyc_data' => ['name' => 'X'],
            'documents' => ['recto' => 'r.jpg'],
            'liveness' => '0',
            'analysis_details' => [
                'document' => ['ocr' => ['nom' => 'X']],
            ],
        ]);

        $payload = (new KycAnalysisMapperHarness)->map($enrollment);

        $this->assertFalse($payload['document_identite']['verifie']);
        $this->assertTrue($payload['etapes']['liveness_effectue']);
    }
}

/**
 * Exposes the trait for unit tests without hitting cloud storage.
 */
final class KycAnalysisMapperHarness
{
    use FormatsEnrollmentDocuments;
    use MapsEnrollmentKycAnalysis;

    /**
     * @return array<string, mixed>
     */
    public function map(EnrollmentRequest $enrollment): array
    {
        return $this->analyseKycPhysique($enrollment);
    }

    /**
     * @param  array<string, mixed>|null  $docs
     */
    protected function documentUrl(?array $docs, string $key): ?string
    {
        if (! is_array($docs) || empty($docs[$key])) {
            return null;
        }

        return 'https://example.test/'.$docs[$key];
    }
}
