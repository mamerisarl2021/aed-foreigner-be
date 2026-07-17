<?php

declare(strict_types=1);

namespace App\Services\Enrollment;

use App\DataTransferObjects\EmailNotificationData;
use App\Enums\NotificationPlatform;
use App\Enums\NotificationTemplate;
use App\Jobs\Notifications\SendEmailNotificationJob;
use App\Models\EnrollmentRequest;
use App\Models\User;
use App\Support\NotificationRecipient;
use Illuminate\Support\Facades\Log;

class EnrollmentSlaService
{
    private const OPEN_STATUSES = [
        'PENDING',
        'VISIO_REQUESTED',
        'APPROVED_BY_AGENT',
        'REJECTED_BY_AGENT',
        'RETURNED_TO_AGENT',
    ];

    /**
     * @return array{checked: int, updated: int}
     */
    public function checkAndNotify(): array
    {
        $maxHours = max(1, (int) config('enrollment.sla.max_hours', 72));
        /** @var array<int, string> $levels */
        $levels = config('enrollment.sla.alert_levels', []);
        krsort($levels);

        $checked = 0;
        $updated = 0;

        EnrollmentRequest::query()
            ->whereIn('status', self::OPEN_STATUSES)
            ->whereNotNull('sla_deadline_at')
            ->orderBy('id')
            ->chunkById(100, function ($enrollments) use ($maxHours, $levels, &$checked, &$updated) {
                foreach ($enrollments as $enrollment) {
                    $checked++;
                    $elapsedHours = $enrollment->created_at->diffInHours(now(), false);
                    $percent = ($elapsedHours / $maxHours) * 100;

                    $newLevel = null;
                    foreach ($levels as $threshold => $levelKey) {
                        if ($percent >= (float) $threshold) {
                            $newLevel = $levelKey;
                            break;
                        }
                    }

                    if ($newLevel === null || $newLevel === $enrollment->sla_alert_level) {
                        continue;
                    }

                    $enrollment->sla_alert_level = $newLevel;
                    $enrollment->save();
                    $updated++;

                    $this->notifyRoles($enrollment, $newLevel);
                }
            });

        return ['checked' => $checked, 'updated' => $updated];
    }

    private function notifyRoles(EnrollmentRequest $enrollment, string $level): void
    {
        /** @var list<string> $roles */
        $roles = config("enrollment.sla.notify_roles.{$level}", []);
        if ($roles === []) {
            return;
        }

        $recipients = User::role($roles)->get();
        if ($recipients->isEmpty()) {
            Log::info('Enrollment SLA alert: no users for roles', [
                'level' => $level,
                'roles' => $roles,
                'enrollment_id' => $enrollment->id,
            ]);

            return;
        }

        $notificationRecipients = [];
        foreach ($recipients as $user) {
            if (! $user->email) {
                continue;
            }
            $notificationRecipients[] = NotificationRecipient::email($user->email, [
                'level' => $level,
                'enrollment_id' => $enrollment->id,
                'email' => $enrollment->email,
                'status' => $enrollment->status,
                'sla_deadline_at' => optional($enrollment->sla_deadline_at)->toIso8601String(),
            ]);
        }

        if ($notificationRecipients === []) {
            return;
        }

        SendEmailNotificationJob::dispatch(new EmailNotificationData(
            subject: "Alerte SLA enrôlement ({$level}) — #{$enrollment->id}",
            template: NotificationTemplate::EnrollmentSlaAlert,
            recipients: $notificationRecipients,
            variables: [
                'level' => $level,
                'enrollment_id' => $enrollment->id,
                'email' => $enrollment->email,
                'status' => $enrollment->status,
                'sla_deadline_at' => optional($enrollment->sla_deadline_at)->toIso8601String(),
            ],
            type: 'ENROLLMENT_SLA_ALERT',
            platform: NotificationPlatform::from(config('notifications.platform')),
        ));
    }
}
