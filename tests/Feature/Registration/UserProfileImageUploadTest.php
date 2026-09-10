<?php

declare(strict_types=1);

namespace Tests\Feature\Registration;

use App\Jobs\UploadUserProfileImageJob;
use App\Models\User;
use App\Services\Registration\UserRegistrationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class UserProfileImageUploadTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function update_user_sets_the_profile_path_before_the_queue_runs(): void
    {
        config(['filesystems.cloud' => 's3']);
        Storage::fake('local');
        Storage::fake('s3');
        Bus::fake();

        $user = User::factory()->create(['email' => 'photo@example.com', 'name' => 'Old']);
        $file = UploadedFile::fake()->image('avatar.jpg');

        $result = app(UserRegistrationService::class)->updateUser($user->id, ['name' => 'Nouveau'], $file);

        $this->assertTrue($result->success);
        $this->assertInstanceOf(User::class, $result->data);
        $this->assertIsString($result->data->profile);
        $this->assertStringStartsWith('images/', $result->data->profile);
        $this->assertSame($result->data->profile, $user->fresh()?->profile);
        Storage::cloud()->assertMissing($result->data->profile);

        $cloudPath = $result->data->profile;
        Bus::assertDispatched(
            UploadUserProfileImageJob::class,
            fn (UploadUserProfileImageJob $job): bool => $job->userId === $user->id
                && $job->cloudPath === $cloudPath
                && str_starts_with($job->localPath, 'tmp/profiles/')
        );
    }

    #[Test]
    public function http_profile_update_returns_a_link_when_the_queue_has_not_run(): void
    {
        config(['filesystems.cloud' => 's3']);
        Storage::fake('local');
        Storage::fake('s3');
        Bus::fake();

        $user = User::factory()->create(['email' => 'photo-http@example.com']);
        Sanctum::actingAs($user);

        $response = $this->post($this->api('/users/'.$user->id), [
            'email' => $user->email,
            'profile' => UploadedFile::fake()->image('avatar.jpg'),
        ], ['Accept' => 'application/json']);

        $response->assertOk();
        $link = $response->json('data.link');
        $this->assertIsString($link);
        $this->assertNotSame('', $link);

        $user->refresh();
        $this->assertIsString($user->profile);
        $this->assertStringStartsWith('images/', $user->profile);
        Storage::cloud()->assertMissing($user->profile);
        Bus::assertDispatched(UploadUserProfileImageJob::class);
    }

    #[Test]
    public function the_job_uploads_bytes_to_the_path_already_stored_on_the_user(): void
    {
        config(['filesystems.cloud' => 's3']);
        Storage::fake('local');
        Storage::fake('s3');

        $user = User::factory()->create([
            'email' => 'photo-job@example.com',
            'profile' => 'images/face.jpg',
        ]);
        Storage::disk('local')->put('tmp/profiles/face.jpg', 'fake-bytes');

        (new UploadUserProfileImageJob($user->id, 'tmp/profiles/face.jpg', 'images/face.jpg'))->handle();

        $this->assertSame('images/face.jpg', $user->fresh()?->profile);
        Storage::cloud()->assertExists('images/face.jpg');
        Storage::disk('local')->assertMissing('tmp/profiles/face.jpg');
    }
}
