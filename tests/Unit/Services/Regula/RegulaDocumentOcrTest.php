<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Regula;

use App\Services\Regula\RegulaDocumentOcr;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class RegulaDocumentOcrTest extends TestCase
{
    #[Test]
    public function it_extracts_document_name_and_normalized_text_fields(): void
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
                                ['fieldType' => 5, 'value' => '12/05/1990'],
                                ['fieldType' => 2, 'value' => 'BJ1234567'],
                                ['fieldType' => 3, 'value' => '01/01/2030'],
                                ['fieldType' => 11, 'value' => 'BEN'],
                                ['fieldType' => 25, 'value' => 'ignored'],
                                ['fieldType' => 8, 'value' => '  '],
                            ],
                        ],
                    ],
                ],
            ],
        ]);

        $this->assertSame('Benin - Passport', $summary['document_name']);
        $this->assertSame('KOWAKOU', $summary['ocr']['nom']);
        $this->assertSame('AMOUR', $summary['ocr']['prenoms']);
        $this->assertSame('1990-05-12', $summary['ocr']['date_naissance']);
        $this->assertSame('2030-01-01', $summary['ocr']['date_expiration']);
        $this->assertSame('BJ1234567', $summary['ocr']['numero_piece']);
        $this->assertSame('BEN', $summary['ocr']['nationalite']);
        $this->assertArrayNotHasKey('ignored', $summary['ocr']);
    }

    #[Test]
    public function it_returns_empty_ocr_when_the_payload_has_no_containers(): void
    {
        $summary = (new RegulaDocumentOcr)->summarize(null);

        $this->assertNull($summary['document_name']);
        $this->assertSame([], $summary['ocr']);
    }
}
