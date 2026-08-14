<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Regula;

use App\Services\Regula\RegulaDocumentOcr;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class RegulaDocumentOcrTest extends TestCase
{
    #[Test]
    public function it_extracts_every_text_field_including_checksums(): void
    {
        $summary = (new RegulaDocumentOcr)->summarize([
            'ContainerList' => [
                'List' => [
                    ['result_type' => 9, 'DocumentName' => 'Benin - Passport'],
                    [
                        'result_type' => 36,
                        'Text' => [
                            'fieldList' => [
                                ['fieldType' => 8, 'value' => 'KOWAKOU'],
                                ['fieldType' => 9, 'value' => 'AMOUR'],
                                ['fieldType' => 12, 'value' => 'M'],
                                ['fieldType' => 5, 'value' => '12/05/1990'],
                                ['fieldType' => 2, 'value' => 'BJ1234567'],
                                ['fieldType' => 3, 'value' => '01/01/2030'],
                                ['fieldType' => 4, 'value' => '01/01/2020'],
                                ['fieldType' => 6, 'value' => 'COTONOU'],
                                ['fieldType' => 11, 'value' => 'BEN'],
                                ['fieldType' => 24, 'fieldName' => 'Authority', 'value' => 'DGI'],
                                ['fieldType' => 25, 'value' => 'KOWAKOU AMOUR'],
                                ['fieldType' => 38, 'value' => 'BENIN'],
                                ['fieldType' => 40, 'value' => '7'],
                                ['fieldType' => 8, 'value' => '  '],
                                ['fieldType' => 50, 'fieldName' => 'Optional Data', 'value' => 'XYZ'],
                            ],
                        ],
                    ],
                ],
            ],
        ]);

        $this->assertSame('Benin - Passport', $summary['document_name']);
        $this->assertSame('KOWAKOU', $summary['ocr']['nom']);
        $this->assertSame('AMOUR', $summary['ocr']['prenoms']);
        $this->assertSame('M', $summary['ocr']['sexe']);
        $this->assertSame('1990-05-12', $summary['ocr']['date_naissance']);
        $this->assertSame('2030-01-01', $summary['ocr']['date_expiration']);
        $this->assertSame('2020-01-01', $summary['ocr']['date_emission']);
        $this->assertSame('BJ1234567', $summary['ocr']['numero_piece']);
        $this->assertSame('BEN', $summary['ocr']['nationalite']);
        $this->assertSame('COTONOU', $summary['ocr']['lieu_naissance']);
        $this->assertSame('DGI', $summary['ocr']['autorite']);
        $this->assertSame('KOWAKOU AMOUR', $summary['ocr']['nom_complet']);
        $this->assertSame('BENIN', $summary['ocr']['pays_emission']);
        $this->assertSame('XYZ', $summary['ocr']['optional_data']);
        $this->assertSame('7', $summary['ocr']['checksum_numero_piece']);
        $this->assertArrayNotHasKey('ignored', $summary['ocr']);
    }

    #[Test]
    public function it_merges_every_text_container_first_wins(): void
    {
        $summary = (new RegulaDocumentOcr)->summarize([
            'ContainerList' => [
                'List' => [
                    ['result_type' => 9, 'DocumentName' => 'Benin - National ID'],
                    [
                        'result_type' => 36,
                        'Text' => [
                            'fieldList' => [
                                ['fieldType' => 8, 'value' => 'RECTO'],
                                ['fieldType' => 2, 'value' => 'BJ111'],
                            ],
                        ],
                    ],
                    [
                        'result_type' => 36,
                        'Text' => [
                            'fieldList' => [
                                ['fieldType' => 8, 'value' => 'VERSO_SHOULD_NOT_WIN'],
                                ['fieldType' => 6, 'value' => 'PARAKOU'],
                                ['fieldType' => 24, 'value' => 'DGI'],
                            ],
                        ],
                    ],
                ],
            ],
        ]);

        $this->assertSame('RECTO', $summary['ocr']['nom']);
        $this->assertSame('BJ111', $summary['ocr']['numero_piece']);
        $this->assertSame('PARAKOU', $summary['ocr']['lieu_naissance']);
        $this->assertSame('DGI', $summary['ocr']['autorite']);
    }

    #[Test]
    public function it_returns_empty_ocr_when_the_payload_has_no_containers(): void
    {
        $summary = (new RegulaDocumentOcr)->summarize(null);

        $this->assertNull($summary['document_name']);
        $this->assertSame([], $summary['ocr']);
    }
}
