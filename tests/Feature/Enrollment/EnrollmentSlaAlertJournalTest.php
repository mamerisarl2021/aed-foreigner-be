<?php

declare(strict_types=1);

namespace Tests\Feature\Enrollment;

use App\Enums\ActivityLogAction;
use App\Enums\EnrollmentStatus;
use App\Jobs\Notifications\SendEmailNotificationJob;
use App\Models\ActivityLog;
use App\Models\EnrollmentRequest;
use App\Models\User;
use App\Services\Enrollment\EnrollmentSlaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class EnrollmentSlaAlertJournalTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function bulk_sla_level_changes_are_written_to_activity_logs(): void
    {
        $enrollment = EnrollmentRequest::query()->create([
            'tracking_code' => 'PKSLA001',
            'email' => 'sla-alert@example.com',
            'phonenumber' => '+2290162405472',
            'status' => EnrollmentStatus::EnAttenteAgent->value,
            'type' => 'PERSONNE_PHYSIQUE',
            'kyc_data' => ['name' => 'SLA'],
            'sla_deadline_at' => now()->subHour(),
        ]);
        $enrollment->forceFill(['created_at' => now()->subHours(80)])->save();

        $result = app(EnrollmentSlaService::class)->checkAndNotify();

        $this->assertSame(1, $result['updated']);
        $this->assertSame('level2', $enrollment->fresh()?->sla_alert_level);
        $this->assertTrue(
            ActivityLog::query()
                ->where('action_code', ActivityLogAction::SlaAlerte->label())
                ->where('enrollment_request_id', $enrollment->id)
                ->exists()
        );

        $second = app(EnrollmentSlaService::class)->checkAndNotify();
        $this->assertSame(0, $second['updated']);
        $this->assertSame(1, ActivityLog::query()
            ->where('action_code', ActivityLogAction::SlaAlerte->label())
            ->where('enrollment_request_id', $enrollment->id)
            ->count());
    }

    #[Test]
    public function sla_alerts_notify_matching_roles_without_loading_every_user(): void
    {
        Bus::fake();

        foreach ([
            config('roles.responsable_de_validation'),
            config('roles.agent'),
        ] as $role) {
            Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        }

        $responsable = User::factory()->create(['email' => 'sla-responsable@example.com']);
        $responsable->assignRole(config('roles.responsable_de_validation'));
        $other = User::factory()->create(['email' => 'sla-agent@example.com']);
        $other->assignRole(config('roles.agent'));

        $enrollment = EnrollmentRequest::query()->create([
            'tracking_code' => 'PKSLA002',
            'email' => 'sla-notify@example.com',
            'phonenumber' => '+2290162405472',
            'status' => EnrollmentStatus::EnAttenteAgent->value,
            'type' => 'PERSONNE_PHYSIQUE',
            'kyc_data' => ['name' => 'SLA'],
            'sla_deadline_at' => now()->subHour(),
        ]);
        $enrollment->forceFill(['created_at' => now()->subHours(80)])->save();

        app(EnrollmentSlaService::class)->checkAndNotify();

        Bus::assertDispatched(
            SendEmailNotificationJob::class,
            function (SendEmailNotificationJob $job) use ($responsable, $other): bool {
                $emails = array_column($job->notification->recipients, 'email');

                return in_array($responsable->email, $emails, true)
                    && ! in_array($other->email, $emails, true);
            }
        );
    }
}
