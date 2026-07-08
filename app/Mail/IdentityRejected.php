<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class IdentityRejected extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $name,
        public string $stage,
        public array $reasons,
        public ?string $comments = null
    ) {}

    public function build()
    {
        return $this->subject('Votre demande d\'identité a été rejetée')
            ->view('emails.identity.rejected')
            ->with([
                'name' => $this->name,
                'stage' => $this->stage,
                'reasons' => $this->reasons,
                'comments' => $this->comments,
            ]);
    }
}
