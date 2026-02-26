<?php

namespace App\Jobs;

use App\Models\StructureInvitation;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Mail;
use App\Mail\StructureInvitationMail;
use Illuminate\Support\Facades\Log;

class SendStructureInvitationEmail implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $invitation;

    public function __construct(StructureInvitation $invitation)
    {
        $this->invitation = $invitation;
    }

    public function handle()
    {
        $invitation = $this->invitation->load('user');

        Log::info("Envoi email invitation", [
            'invitation_id' => $invitation->id,
            'email' => $invitation->email,
            'user_id' => optional($invitation->user)->id,
            'user_email' => optional($invitation->user)->email,
        ]);

        if (!$invitation->user) {
            Log::error('Invitation sans user associé', [
                'invitation_id' => $invitation->id,
            ]);

            return;
        }
        
        Mail::to($invitation->email)
            ->send(new StructureInvitationMail($invitation, $invitation->user));
    }
}