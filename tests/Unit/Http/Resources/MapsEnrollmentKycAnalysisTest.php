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
                'document' => [
                    'ocr' => [
                        'nom' => 'KOTO',
                        'prenoms' => 'Ada',
                        'numero_piece' => 'AB123456',
                        'date_naissance' => '2000-05-25',
                        'nationalite' => 'BJ',
                    ],
                ],
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
    public function it_uses_every_ocr_field_and_ignores_declared_kyc(): void
    {
        $enrollment = new EnrollmentRequest([
            'kyc_data' => [
                'name' => 'SAISI',
                'document_number' => 'DECLARE',
                'country_of_residence' => 'France',
                'sexe' => 'F',
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
                        'sexe' => 'M',
                        'date_emission' => '2016-10-17',
                        'lieu_naissance' => 'ABIDJAN',
                        'autorite' => 'MINISTERE DE L\'INTERIEUR',
                        'pays_emission' => 'Côte d\'Ivoire',
                        'checksum_numero_piece' => '7',
                        'mrz' => 'P<CIVKOUASSI<<YAO',
                    ],
                ],
            ],
        ]);

        $payload = (new KycAnalysisMapperHarness)->map($enrollment);
        $identite = $payload['document_identite'];

        $this->assertSame('Passport', $identite['type_piece']);
        $this->assertSame('Côte d\'Ivoire', $identite['pays']);
        $this->assertTrue($identite['verifie']);
        $this->assertSame('CI999', $identite['numero_document']);
        $this->assertSame('KOUASSI', $identite['nom']);
        $this->assertSame('YAO', $identite['prenoms']);
        $this->assertSame('1999-10-17', $identite['date_naissance']);
        $this->assertSame('CIV', $identite['nationalite']);
        $this->assertSame('2026-10-17', $identite['date_expiration']);
        $this->assertSame('M', $identite['sexe']);
        $this->assertSame('2016-10-17', $identite['date_emission']);
        $this->assertSame('ABIDJAN', $identite['lieu_naissance']);
        $this->assertSame('MINISTERE DE L\'INTERIEUR', $identite['autorite']);
        $this->assertSame('7', $identite['checksum_numero_piece']);
        $this->assertSame('P<CIVKOUASSI<<YAO', $identite['mrz']);
        $this->assertNotSame('SAISI', $identite['nom']);
        $this->assertArrayNotHasKey('document_number', $identite);
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
    public function declared_form_kyc_is_not_treated_as_extracted_ocr(): void
    {
        $enrollment = new EnrollmentRequest([
            'kyc_data' => ['name' => 'SAISI', 'document_number' => 'DECLARE'],
            'documents' => ['recto' => 'r.jpg'],
            'liveness' => '0',
            'similarity' => '0.9',
            'analysis_details' => [
                'doc_validity' => true,
                'document' => ['document_name' => 'Benin - Passport'],
            ],
        ]);

        $payload = (new KycAnalysisMapperHarness)->map($enrollment);

        $this->assertNull($payload['document_identite']['nom']);
        $this->assertNull($payload['document_identite']['numero_document']);
        $this->assertFalse($payload['etapes']['informations_extraites']);
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
    public function mock_ocr_data_without_identity_fields_is_ignored(): void
    {
        $enrollment = new EnrollmentRequest([
            'kyc_data' => ['name' => 'SAISI', 'email' => 'ada@example.com'],
            'documents' => ['recto' => 'r.jpg'],
            'liveness' => '0',
            'analysis_details' => [
                'doc_validity' => true,
                'ocr_data' => [
                    'email' => 'ada@example.com',
                    'liveness' => 'skipped',
                    'liveness_transaction_id' => 'tx-1',
                ],
                'document' => [
                    'ocr' => [
                        'nom' => 'KOUASSI',
                        'numero_piece' => 'CI999',
                    ],
                ],
            ],
        ]);

        $payload = (new KycAnalysisMapperHarness)->map($enrollment);

        $this->assertSame('KOUASSI', $payload['document_identite']['nom']);
        $this->assertSame('CI999', $payload['document_identite']['numero_document']);
        $this->assertArrayNotHasKey('email', $payload['document_identite']);
        $this->assertArrayNotHasKey('liveness', $payload['document_identite']);
        $this->assertArrayNotHasKey('liveness_transaction_id', $payload['document_identite']);
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

    #[Test]
    public function morale_analyse_kyc_does_not_use_company_legal_name_as_document_identity(): void
    {
        $enrollment = new EnrollmentRequest([
            'kyc_data' => [
                'legal_name' => 'TECH SARL INNOV',
                'activity_sector' => 'Services',
            ],
            'documents' => [
                'recto' => 'docs/id.jpg',
                'selfie' => 'selfies/face.jpg',
                'trade_register_extract' => 'docs/rccm.pdf',
            ],
            'liveness' => '0',
            'similarity' => '0.92',
            'analysis_details' => [
                'doc_validity' => true,
                'document' => [
                    'document_name' => 'Benin - Passport',
                    'ocr' => [
                        'nom' => 'KOTO',
                        'prenoms' => 'Ada',
                        'numero_piece' => 'AB123',
                    ],
                ],
            ],
        ]);

        $payload = (new KycAnalysisMapperHarness)->mapMorale($enrollment);

        $this->assertSame('KOTO', $payload['document_identite']['nom']);
        $this->assertSame('Ada', $payload['document_identite']['prenoms']);
        $this->assertSame('AB123', $payload['document_identite']['numero_document']);
        $this->assertNotSame('TECH SARL INNOV', $payload['document_identite']['nom']);
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
     * @return array<string, mixed>
     */
    public function mapMorale(EnrollmentRequest $enrollment): array
    {
        return $this->analyseKycMorale($enrollment);
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
