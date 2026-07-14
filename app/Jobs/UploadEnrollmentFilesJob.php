<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Identity;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

class UploadEnrollmentFilesJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /**
     * @param int $identityId
     * @param array<string, string|null> $localPaths
     */
    public function __construct(
        public readonly int $identityId,
        public readonly array $localPaths
    ) {}

    public function handle(): void
    {
        $identity = Identity::find($this->identityId);
        if (! $identity) {
            Log::error("UploadEnrollmentFilesJob: Identity {$this->identityId} not found.");
            return;
        }

        /** @var array<string, mixed> $proof */
        $proof = is_string($identity->proof) ? json_decode($identity->proof, true) : (array) $identity->proof;

        foreach ($this->localPaths as $key => $localPath) {
            if ($localPath && Storage::disk('local')->exists($localPath)) {
                $cloudPath = ($key === 'selfie') ? 'selfies/' . basename($localPath) : 'images/' . basename($localPath);
                
                // Read from local and upload to S3
                $fileContents = Storage::disk('local')->get($localPath);
                if ($fileContents !== null && Storage::cloud()->put($cloudPath, $fileContents)) {
                    $proof[$key . 'Path'] = $cloudPath;
                    Storage::disk('local')->delete($localPath);
                } else {
                    Log::error("UploadEnrollmentFilesJob: Failed to upload {$localPath} to cloud.");
                    // Throw to retry the job
                    throw new \RuntimeException("Failed to upload file to cloud storage.");
                }
            }
        }

        $identity->proof = json_encode($proof);
        $identity->save();
    }
}
