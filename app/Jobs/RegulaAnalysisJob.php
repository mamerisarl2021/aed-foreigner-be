<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\EnrollmentRequest;
use App\Services\RegulaService;
use Exception;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class RegulaAnalysisJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public readonly int $enrollmentRequestId,
    ) {}

    public function handle(RegulaService $regulaService): void
    {
        Log::info("Starting Regula analysis for EnrollmentRequest ID: {$this->enrollmentRequestId}");

        try {
            $enrollment = EnrollmentRequest::find($this->enrollmentRequestId);
            if (! $enrollment) {
                Log::error("EnrollmentRequest not found: {$this->enrollmentRequestId}");

                return;
            }

            $documents = $enrollment->documents ?? [];
            $kycData = $enrollment->kyc_data ?? [];

            $result = $regulaService->analyzeIdentity([], array_merge($kycData, [
                'documents' => $documents,
                'liveness' => $enrollment->liveness,
                'similarity' => $enrollment->similarity,
            ]));

            if ($result['status'] === 'OK') {
                $enrollment->risk_score = (string) $result['risk_score'];
                $enrollment->analysis_details = $result['details'] ?? [];
                $enrollment->save();

                Log::info("Regula analysis completed for EnrollmentRequest ID: {$this->enrollmentRequestId}. Score: {$result['risk_score']}");
            } else {
                Log::warning("Regula analysis returned non-OK status for EnrollmentRequest ID: {$this->enrollmentRequestId}");
            }
        } catch (Exception $e) {
            Log::error('Error in RegulaAnalysisJob: '.$e->getMessage());
        }
    }
}
