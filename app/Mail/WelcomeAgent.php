<?php

declare(strict_types=1);

namespace App\Mail;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class WelcomeAgent extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public User $user,
        public string $link,
    ) {}

    public function build(): static
    {
        return $this->from('collabone@qualitycorporate.com')
            ->subject('Ajout d\'un compte agent')->view('emails.agent_added_to_aed')->with([
                'user' => $this->user,
                'link' => $this->link,
            ]);
    }
}
