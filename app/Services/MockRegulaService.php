<?php

namespace App\Services;

class MockRegulaService implements RegulaService
{
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
