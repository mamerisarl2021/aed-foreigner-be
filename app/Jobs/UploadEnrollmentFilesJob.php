<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\EnrollmentRequest;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class UploadEnrollmentFilesJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /**
     * @param  array<string, string|null>  $localPaths
     */
    public function __construct(
        public readonly int $enrollmentRequestId,
        public readonly array $localPaths,
    ) {}

    public function handle(): void
    {
        $enrollment = EnrollmentRequest::find($this->enrollmentRequestId);
        if (! $enrollment) {
            Log::error("UploadEnrollmentFilesJob: EnrollmentRequest {$this->enrollmentRequestId} not found.");

            return;
        }

        /** @var array<string, string|null> $documents */
        $documents = $enrollment->documents ?? [];

        foreach ($this->localPaths as $key => $localPath) {
            if (! $localPath || ! Storage::disk('local')->exists($localPath)) {
                continue;
            }

            $cloudPath = ($key === 'selfie')
                ? 'selfies/'.basename($localPath)
                : 'images/'.basename($localPath);

            $fileContents = Storage::disk('local')->get($localPath);
            if ($fileContents === null || ! Storage::cloud()->put($cloudPath, $fileContents)) {
                Log::error("UploadEnrollmentFilesJob: Failed to upload {$localPath} to cloud.");
                throw new \RuntimeException('Failed to upload file to cloud storage.');
            }

            $documents[$key] = $cloudPath;
            Storage::disk('local')->delete($localPath);
        }

        $enrollment->documents = $documents;
        $enrollment->save();
    }
}
