<?php

namespace App\Mail;

use App\Models\StructureInvitation;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class StructureInvitationMail extends Mailable
{
    use Queueable, SerializesModels;

    public $invitation;
    public $user;
    public $acceptUrl;
    public $rejectUrl;

    public function __construct(StructureInvitation $invitation, User $user)
    {
        $this->invitation = $invitation;
        $this->user = $user;
        $this->acceptUrl = url("/api/invitations/{$invitation->token}/accept");
        $this->rejectUrl = url("/api/invitations/{$invitation->token}/reject");
    }

    public function build()
    {
        return $this->subject('Invitation à rejoindre ' . $this->invitation->structure->name)
                    ->markdown('emails.structure-invitation')
                    ->with([
                        'user' => $this->user,
                        'invitation' => $this->invitation,
                        'acceptUrl' => $this->acceptUrl,
                        'rejectUrl' => $this->rejectUrl
                    ]);
    }
}