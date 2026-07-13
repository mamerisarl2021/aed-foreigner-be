<?php

namespace App\Services;

class MockRegulaService implements RegulaService
{
    public function analyzeIdentity(array $files, array $data): array
    {
        // Simulate processing time
        sleep(2);

        // Simulate a successful analysis with a random risk score low enough to pass most checks
        // or occasionally high to test rejection logic
        $riskScore = rand(0, 10);

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
