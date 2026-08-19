<?php

declare(strict_types=1);

namespace App\Mail;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class ResetPassword extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public User $user,
        public string $link,
    ) {}

    public function build(): static
    {
        return $this->from('collabone@qualitycorporate.com')
            ->subject('Lien de réinitialisation')->view('emails.reset_link')->with([
                'user' => $this->user,
                'link' => $this->link,
            ]);
    }
}
