<?php

declare(strict_types=1);

namespace App\Services\Enrollment;

use App\DataTransferObjects\EmailNotificationData;
use App\Enums\ActivityLogAction;
use App\Enums\EnrollmentStatus;
use App\Enums\NotificationPlatform;
use App\Enums\NotificationTemplate;
use App\Jobs\Notifications\SendEmailNotificationJob;
use App\Models\EnrollmentRequest;
use App\Models\User;
use App\Services\ActivityLog\ActivityLogService;
use App\Support\NotificationRecipient;
use Illuminate\Support\Facades\Log;

class EnrollmentSlaService
{
    public function __construct(
        private readonly ActivityLogService $activityLog,
    ) {}

    /**
     * @return array{checked: int, updated: int, correction_reminded: int, correction_archived: int}
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
            ->whereIn('status', EnrollmentStatus::open())
            ->whereNotNull('sla_deadline_at')
            ->orderBy('id')
            ->chunkById(100, function ($enrollments) use ($maxHours, $levels, &$checked, &$updated) {
                foreach ($enrollments as $enrollment) {
                    $checked++;
                    $this->applySlaLevel($enrollment, $maxHours, $levels, $updated);
                }
            });

        $correction = $this->checkMoraleCorrections();

        return [
            'checked' => $checked,
            'updated' => $updated,
            'correction_reminded' => $correction['reminded'],
            'correction_archived' => $correction['archived'],
        ];
    }

    /**
     * @return array{reminded: int, archived: int}
     */
    public function checkMoraleCorrections(): array
    {
        $reminderHours = max(1, (int) config('enrollment.morale.correction_reminder_hours', 24));
        $reminded = 0;
        $archived = 0;

        EnrollmentRequest::query()
            ->where('type', 'PERSONNE_MORALE')
            ->where('status', EnrollmentStatus::ACorriger)
            ->whereNotNull('correction_deadline_at')
            ->with(['submittedBy', 'assignedAgent'])
            ->orderBy('id')
            ->chunkById(100, function ($enrollments) use ($reminderHours, &$reminded, &$archived) {
                foreach ($enrollments as $enrollment) {
                    $this->processMoraleCorrection($enrollment, $reminderHours, $reminded, $archived);
                }
            });

        return ['reminded' => $reminded, 'archived' => $archived];
    }

    /**
     * @param  array<int, string>  $levels
     */
    private function applySlaLevel(EnrollmentRequest $enrollment, int $maxHours, array $levels, int &$updated): void
    {
        $createdAt = $enrollment->created_at;
        if ($createdAt === null) {
            return;
        }

        $elapsedHours = $createdAt->diffInHours(now(), false);
        $percent = ($elapsedHours / $maxHours) * 100;

        $newLevel = null;
        foreach ($levels as $threshold => $levelKey) {
            if ($percent >= (float) $threshold) {
                $newLevel = $levelKey;
                break;
            }
        }

        if ($newLevel === null || $newLevel === $enrollment->sla_alert_level) {
            return;
        }

        $enrollment->sla_alert_level = $newLevel;
        $enrollment->save();
        $updated++;

        $this->notifyRoles($enrollment, $newLevel);
    }

    private function processMoraleCorrection(
        EnrollmentRequest $enrollment,
        int $reminderHours,
        int &$reminded,
        int &$archived,
    ): void {
        $deadline = $enrollment->correction_deadline_at;
        if ($deadline === null) {
            return;
        }

        if ($deadline->isPast()) {
            if ($this->archiveExpiredCorrectionIfPending($enrollment)) {
                $archived++;
                $this->notifyCorrectionExpired($enrollment);
            }

            return;
        }

        if ($enrollment->correction_reminder_sent_at !== null) {
            return;
        }

        if (! $deadline->lessThanOrEqualTo(now()->addHours($reminderHours))) {
            return;
        }

        if (! $this->markCorrectionReminderIfPending($enrollment)) {
            return;
        }

        $reminded++;
        $this->notifyCorrectionReminder($enrollment);
    }

    public function archiveExpiredCorrectionIfPending(EnrollmentRequest $enrollment): bool
    {
        $updated = EnrollmentRequest::query()
            ->whereKey($enrollment->id)
            ->where('status', EnrollmentStatus::ACorriger)
            ->where('correction_deadline_at', '<', now())
            ->update(['status' => EnrollmentStatus::Rejetee->value]);

        if ($updated === 0) {
            return false;
        }

        $enrollment->refresh();
        $this->activityLog->record(
            ActivityLogAction::CorrectionMoraleExpiree,
            sprintf(
                'Le délai de correction de la demande morale %s est dépassé. Dossier archivé.',
                $enrollment->tracking_code ?? $enrollment->id
            ),
            null,
            $enrollment->id,
        );

        return true;
    }

    private function markCorrectionReminderIfPending(EnrollmentRequest $enrollment): bool
    {
        $updated = EnrollmentRequest::query()
            ->whereKey($enrollment->id)
            ->where('status', EnrollmentStatus::ACorriger)
            ->whereNull('correction_reminder_sent_at')
            ->update(['correction_reminder_sent_at' => now()]);

        if ($updated === 0) {
            return false;
        }

        $enrollment->refresh();

        return true;
    }

    private function notifyRoles(EnrollmentRequest $enrollment, string $level): void
    {
        /** @var list<string> $roles */
        $roles = config("enrollment.sla.notify_roles.{$level}", []);
        if ($roles === []) {
            return;
        }

        $recipients = collect();
        User::role($roles)->chunkById(100, function ($users) use ($recipients): void {
            $recipients->push(...$users);
        });
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
                'status' => $enrollment->status->value,
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
                'status' => $enrollment->status->value,
                'sla_deadline_at' => optional($enrollment->sla_deadline_at)->toIso8601String(),
            ],
            type: 'ENROLLMENT_SLA_ALERT',
            platform: NotificationPlatform::from(config('notifications.platform')),
        ));
    }

    private function notifyCorrectionReminder(EnrollmentRequest $enrollment): void
    {
        $enrollment->loadMissing('submittedBy');
        $email = $enrollment->submittedBy instanceof User
            ? $enrollment->submittedBy->email
            : $enrollment->email;

        if ($email === '') {
            return;
        }

        $legalName = $enrollment->applicantDisplayName();
        $deadline = $enrollment->correction_deadline_at?->toIso8601String();

        SendEmailNotificationJob::dispatch(new EmailNotificationData(
            subject: 'Rappel : corrigez votre demande personne morale',
            template: NotificationTemplate::MoraleCorrectionReminder,
            recipients: [NotificationRecipient::email($email, [
                'name' => $legalName,
                'correction_deadline_at' => $deadline,
            ])],
            variables: [
                'name' => $legalName,
                'correction_deadline_at' => $deadline,
                'numero_suivi' => $enrollment->tracking_code,
            ],
            type: 'MORALE_CORRECTION_REMINDER',
            platform: NotificationPlatform::from(config('notifications.platform')),
        ));
    }

    private function notifyCorrectionExpired(EnrollmentRequest $enrollment): void
    {
        $agent = $enrollment->assignedAgent;
        $recipients = [];

        if ($agent instanceof User && $agent->email !== '') {
            $recipients[] = NotificationRecipient::email($agent->email, [
                'enrollment_id' => $enrollment->id,
                'numero_suivi' => $enrollment->tracking_code,
            ]);
        } else {
            User::role(config('roles.agent'))->chunkById(100, function ($users) use (&$recipients, $enrollment): void {
                foreach ($users as $user) {
                    if ($user->email === '') {
                        continue;
                    }
                    $recipients[] = NotificationRecipient::email($user->email, [
                        'enrollment_id' => $enrollment->id,
                        'numero_suivi' => $enrollment->tracking_code,
                    ]);
                }
            });
        }

        if ($recipients === []) {
            Log::info('Morale correction expired: no agent to notify', [
                'enrollment_id' => $enrollment->id,
            ]);

            return;
        }

        SendEmailNotificationJob::dispatch(new EmailNotificationData(
            subject: 'Dossier morale archivé — délai de correction dépassé',
            template: NotificationTemplate::MoraleCorrectionExpired,
            recipients: $recipients,
            variables: [
                'enrollment_id' => $enrollment->id,
                'numero_suivi' => $enrollment->tracking_code,
                'raison_sociale' => $enrollment->applicantDisplayName(),
            ],
            type: 'MORALE_CORRECTION_EXPIRED',
            platform: NotificationPlatform::from(config('notifications.platform')),
        ));
    }
}
