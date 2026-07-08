<?php

namespace App\Jobs;

use App\DataTransferObjects\EmailNotificationData;
use App\Enums\NotificationPlatform;
use App\Enums\NotificationTemplate;
use App\Jobs\Notifications\SendEmailNotificationJob;
use App\Models\StructureInvitation;
use App\Support\NotificationRecipient;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

final class SendStructureInvitationEmail implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly StructureInvitation $invitation,
    ) {}

    public function handle(): void
    {
        $invitation = $this->invitation->load(['user', 'structure']);

        Log::info('Envoi email invitation', [
            'invitation_id' => $invitation->id,
            'email' => $invitation->email,
            'user_id' => $invitation->user?->id,
            'user_email' => $invitation->user?->email,
        ]);

        if ($invitation->user === null) {
            Log::error('Invitation sans user associé', [
                'invitation_id' => $invitation->id,
            ]);

            return;
        }

        $acceptUrl = url("/api/v1/invitations/{$invitation->token}/accept");
        $rejectUrl = url("/api/v1/invitations/{$invitation->token}/reject");

        $variables = [
            'user' => $invitation->user,
            'invitation' => $invitation,
            'acceptUrl' => $acceptUrl,
            'rejectUrl' => $rejectUrl,
        ];

        SendEmailNotificationJob::dispatch(new EmailNotificationData(
            subject: 'Invitation à rejoindre '.$invitation->structure->name,
            template: NotificationTemplate::StructureInvitation,
            recipients: [
                NotificationRecipient::email($invitation->email, $variables),
            ],
            variables: $variables,
            type: 'STRUCTURE_INVITATION',
            platform: NotificationPlatform::from(config('notifications.platform')),
        ));
    }
}
