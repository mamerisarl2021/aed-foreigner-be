<?php

declare(strict_types=1);

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class SendInitLink extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public mixed $code,
        public string $email,
        public string $type,
    ) {}

    public function build(): static
    {
        return $this->from('collabone@qualitycorporate.com')
            ->subject('Demande du lien de mise à jour')->view('emails.send_init_link')->with([
                'code' => $this->code,
                'email' => $this->email,
                'type' => $this->type,
            ]);
    }
}
