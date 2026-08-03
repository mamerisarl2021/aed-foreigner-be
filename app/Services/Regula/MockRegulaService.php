<?php

declare(strict_types=1);

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
        $similarity = 0.92;
        $riskScore = (int) max(0, min(10, (int) round((1 - $similarity) * 10)));

        return [
            'status' => 'OK',
            'risk_score' => $riskScore,
            'similarity' => $similarity,
            'liveness' => 'skipped',
            'details' => [
                'face_match' => true,
                'doc_validity' => true,
                'ocr_data' => $data,
                'mock' => true,
            ],
        ];
    }
}
