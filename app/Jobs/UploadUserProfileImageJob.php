<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\User;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class UploadUserProfileImageJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 120;

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [60, 120];
    }

    public function __construct(
        public readonly string $userId,
        public readonly string $localPath,
        public readonly string $cloudPath,
    ) {}

    public function handle(): void
    {
        $user = User::query()->find($this->userId);
        if ($user === null) {
            Log::error("UploadUserProfileImageJob: User {$this->userId} not found.");

            return;
        }

        if (! Storage::disk('local')->exists($this->localPath)) {
            Log::error("UploadUserProfileImageJob: Local file {$this->localPath} missing.");

            return;
        }

        $contents = Storage::disk('local')->get($this->localPath);
        if (! is_string($contents)) {
            throw new \RuntimeException('Failed to read local profile image.');
        }

        if (! Storage::cloud()->put($this->cloudPath, $contents)) {
            throw new \RuntimeException('Failed to upload profile image to cloud storage.');
        }

        if ($user->profile !== $this->cloudPath) {
            $user->profile = $this->cloudPath;
            $user->save();
        }
        Storage::disk('local')->delete($this->localPath);
    }
}
