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

    public int $timeout = 180;

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [60, 120];
    }

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

            $files = $this->downloadSlots($enrollment, $tempPaths);
            $result = $regulaService->analyzeIdentity($files, [
                'email' => $enrollment->email,
                'liveness' => $enrollment->liveness,
            ]);

            $this->persistResult($enrollment, $result);
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
     * @param  list<string>  $tempPaths
     * @return array<string, string>
     */
    private function downloadSlots(EnrollmentRequest $enrollment, array &$tempPaths): array
    {
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

        return $files;
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private function persistResult(EnrollmentRequest $enrollment, array $result): void
    {
        $enrollment->risk_score = isset($result['risk_score']) ? (string) $result['risk_score'] : $enrollment->risk_score;
        if (array_key_exists('similarity', $result) && $result['similarity'] !== null) {
            $enrollment->similarity = (string) $result['similarity'];
        }
        if (array_key_exists('liveness', $result) && $result['liveness'] !== null) {
            $enrollment->liveness = (string) $result['liveness'];
        }
        $enrollment->analysis_details = $result['details'] ?? $result;
        $enrollment->save();

        if (($result['status'] ?? '') === 'OK') {
            Log::info("Regula analysis completed for EnrollmentRequest ID: {$this->enrollmentRequestId}. Score: ".($result['risk_score'] ?? 'n/a'));

            return;
        }

        $details = $result['details'] ?? null;
        Log::warning("Regula analysis returned non-OK status for EnrollmentRequest ID: {$this->enrollmentRequestId}", [
            'error' => is_array($details) ? ($details['error'] ?? null) : null,
        ]);
    }
}
