<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\EnrollmentRequest;
use App\Services\Regula\RegulaService;
use Exception;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class RegulaAnalysisJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public function __construct(
        public readonly string $enrollmentRequestId,
    ) {}

    public function handle(RegulaService $regulaService): void
    {
        Log::info("Starting Regula analysis for EnrollmentRequest ID: {$this->enrollmentRequestId}");

        $tempPaths = [];

        try {
            $enrollment = EnrollmentRequest::find($this->enrollmentRequestId);
            if (! $enrollment) {
                Log::error("EnrollmentRequest not found: {$this->enrollmentRequestId}");

                return;
            }

            $documents = $enrollment->documents ?? [];
            $files = [];

            foreach (['selfie', 'recto', 'verso'] as $slot) {
                $cloudPath = $documents[$slot] ?? null;
                if (! is_string($cloudPath) || $cloudPath === '') {
                    continue;
                }
                if (! Storage::cloud()->exists($cloudPath)) {
                    Log::warning("RegulaAnalysisJob: missing cloud file {$cloudPath}");

                    continue;
                }
                $bytes = Storage::cloud()->get($cloudPath);
                if ($bytes === null || $bytes === '') {
                    continue;
                }
                $ext = pathinfo($cloudPath, PATHINFO_EXTENSION) ?: 'jpg';
                $local = sys_get_temp_dir().'/regula_'.$this->enrollmentRequestId.'_'.$slot.'.'.$ext;
                file_put_contents($local, $bytes);
                $tempPaths[] = $local;
                $files[$slot] = $local;
            }

            $result = $regulaService->analyzeIdentity($files, [
                'email' => $enrollment->email,
                'liveness' => $enrollment->liveness,
            ]);

            $enrollment->risk_score = isset($result['risk_score']) ? (string) $result['risk_score'] : $enrollment->risk_score;
            if (array_key_exists('similarity', $result) && $result['similarity'] !== null) {
                $enrollment->similarity = (string) $result['similarity'];
            }
            if (array_key_exists('liveness', $result) && $result['liveness'] !== null) {
                $enrollment->liveness = (string) $result['liveness'];
            }
            $enrollment->analysis_details = $this->mergeAnalysisDetails(
                $enrollment->analysis_details,
                $result['details'] ?? $result,
            );
            $enrollment->save();

            if (($result['status'] ?? '') === 'OK') {
                Log::info("Regula analysis completed for EnrollmentRequest ID: {$this->enrollmentRequestId}. Score: ".($result['risk_score'] ?? 'n/a'));
            } else {
                Log::warning("Regula analysis returned non-OK status for EnrollmentRequest ID: {$this->enrollmentRequestId}", [
                    'error' => $result['details']['error'] ?? null,
                ]);
            }
        } catch (Exception $e) {
            Log::error('Error in RegulaAnalysisJob: '.$e->getMessage());
            throw $e;
        } finally {
            foreach ($tempPaths as $path) {
                if (is_file($path)) {
                    @unlink($path);
                }
            }
        }
    }

    /**
     * Keep the KYC selfie capture instant across re-analysis (Regula details replace the rest).
     *
     * @param  array<string, mixed>|null  $existing
     * @return array<string, mixed>
     */
    private function mergeAnalysisDetails(?array $existing, mixed $incoming): array
    {
        $existing ??= [];
        $merged = is_array($incoming) ? $incoming : [];
        unset($merged['selfie_captured_at']);

        $capturedAt = $existing['selfie_captured_at'] ?? null;
        if (is_string($capturedAt) && $capturedAt !== '') {
            $merged['selfie_captured_at'] = $capturedAt;
        }

        return $merged;
    }
}
