<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class UserSubscriptionValidated extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public $user;

    public $processId;

    public function __construct($user, $processId)
    {
        $this->user = $user;
        $this->processId = $processId;
    }

    public function build()
    {
        return $this->from('collabone@qualitycorporate.com')
            ->subject('Souscription validée')->view('emails.user_subscription_validated')->with([
                'user' => $this->user,
                'processId' => $this->processId,
            ]);
    }
}
