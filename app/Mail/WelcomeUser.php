<?php

declare(strict_types=1);

namespace App\Mail;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class WelcomeUser extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public User $user,
        public string $activationUrl,
        public bool $hasAccount = false,
    ) {}

    public function build(): static
    {
        return $this->from('collabone@qualitycorporate.com')
            ->subject('Activation de votre compte client')
            ->view('emails.user_added_to_aed')
            ->with([
                'user' => $this->user,
                'activationUrl' => $this->activationUrl,
                'hasAccount' => $this->hasAccount,
            ]);
    }
}
