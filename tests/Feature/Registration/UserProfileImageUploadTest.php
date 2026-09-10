<?php

declare(strict_types=1);

namespace Tests\Feature\Registration;

use App\Jobs\UploadUserProfileImageJob;
use App\Models\User;
use App\Services\Registration\UserRegistrationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class UserProfileImageUploadTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function profile_photo_is_uploaded_via_the_queued_job(): void
    {
        config(['filesystems.cloud' => 's3', 'queue.default' => 'sync']);
        Storage::fake('local');
        Storage::fake('s3');

        $user = User::factory()->create(['email' => 'photo@example.com', 'name' => 'Old']);
        $file = UploadedFile::fake()->image('avatar.jpg');

        $result = app(UserRegistrationService::class)->updateUser($user->id, ['name' => 'Nouveau'], $file);

        $this->assertTrue($result->success);
        $user->refresh();
        $this->assertIsString($user->profile);
        $this->assertStringStartsWith('images/', $user->profile);
        Storage::cloud()->assertExists($user->profile);
    }

    #[Test]
    public function the_job_writes_the_cloud_path_on_the_user(): void
    {
        config(['filesystems.cloud' => 's3']);
        Storage::fake('local');
        Storage::fake('s3');

        $user = User::factory()->create(['email' => 'photo-job@example.com']);
        Storage::disk('local')->put('tmp/profiles/face.jpg', 'fake-bytes');

        (new UploadUserProfileImageJob($user->id, 'tmp/profiles/face.jpg'))->handle();

        $this->assertSame('images/face.jpg', $user->fresh()?->profile);
        Storage::cloud()->assertExists('images/face.jpg');
        Storage::disk('local')->assertMissing('tmp/profiles/face.jpg');
    }
}
