<?php

namespace App\Jobs;

use App\Models\Identity;
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

    public $identityId;

    /**
     * Create a new job instance.
     *
     * @return void
     */
    public function __construct(int $identityId)
    {
        $this->identityId = $identityId;
    }

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle(RegulaService $regulaService)
    {
        Log::info("Starting Regula analysis (FaceMatch + DocValid) for Identity ID: {$this->identityId}");

        try {
            $identity = Identity::find($this->identityId);
            if (!$identity) {
                Log::error("Identity not found: {$this->identityId}");
                return;
            }

            // In a real scenario, we would retrieve files from Storage based on $identity->proof
            // For the mock, we pass the proof array
            $proof = json_decode($identity->proof, true) ?? [];
            
            $result = $regulaService->analyzeIdentity([], $proof);

            if ($result['status'] === 'OK') {
                $identity->risk_score = $result['risk_score'];
                $identity->analysis_details = json_encode($result['details']);
                // We don't automatically approve, we just enrich the data for the agent
                // But we could auto-reject if score is too high (e.g. > 90)
                // For now, let's just save the score.
                
                // Update status to indicate analysis is done if we want granular status
                // $identity->status = 'ANALYZED'; 
                
                $identity->save();
                Log::info("Regula analysis completed for Identity ID: {$this->identityId}. Score: {$result['risk_score']}");
            } else {
                Log::warning("Regula analysis returned non-OK status for Identity ID: {$this->identityId}");
            }

        } catch (Exception $e) {
            Log::error("Error in RegulaAnalysisJob: " . $e->getMessage());
            // Optionally release the job back to queue
            // $this->release(60); 
        }
    }
}
