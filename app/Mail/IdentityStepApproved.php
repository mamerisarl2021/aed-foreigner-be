<?php

declare(strict_types=1);

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class IdentityStepApproved extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $name,
    ) {}

    public function build(): static
    {
        return $this->subject("Votre demande a passé l'étape agent")
            ->view('emails.identity.step_approved')
            ->with(['name' => $this->name]);
    }
}
