<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class WelcomeUser extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public $user;

    // public $otp;
    public $activationUrl;

    public $hasAccount;

    public function __construct(
        $user,
        //  $otp,
        $activationUrl,
        $hasAccount = false
    ) {
        $this->user = $user;
        // $this->otp = $otp;
        $this->activationUrl = $activationUrl;
        $this->hasAccount = $hasAccount;
    }

    public function build()
    {
        return $this->from('collabone@qualitycorporate.com')
            ->subject('Activation de votre compte client')
            ->view('emails.user_added_to_aed')
            ->with([
                'user' => $this->user,
                // 'otp' => $this->otp,
                'activationUrl' => $this->activationUrl,
                'hasAccount' => $this->hasAccount,
            ]);
    }
}
