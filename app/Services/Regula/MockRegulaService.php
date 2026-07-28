<?php

namespace App\Services\Regula;

class MockRegulaService implements RegulaService
{
    /**
     * @param  array<string, mixed>  $files
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function analyzeIdentity(array $files, array $data): array
    {
        $riskScore = random_int(0, 10);

        return [
            'status' => 'OK',
            'risk_score' => $riskScore,
            'details' => [
                'face_match' => true,
                'doc_validity' => true,
                'ocr_data' => $data,
            ],
        ];
    }
}
