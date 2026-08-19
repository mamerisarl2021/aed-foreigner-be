<?php

declare(strict_types=1);

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class ForeignerFinalized extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $type
    ) {
        $this->subject('Votre demande a été enregistrée');
    }

    public function build(): static
    {
        return $this->view('emails.foreigner.finalized')
            ->with(['type' => $this->type]);
    }
}
