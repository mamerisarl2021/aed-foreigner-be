<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class ForeignerInitRegistration extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $registrationLink
    ) {
        $this->subject('Complétez votre inscription');
    }

    public function build()
    {
        return $this->view('emails.foreigner.init_registration')
            ->with(['link' => $this->registrationLink]);
    }
}
