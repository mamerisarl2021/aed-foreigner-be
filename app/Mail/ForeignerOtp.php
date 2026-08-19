<?php

declare(strict_types=1);

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class ForeignerOtp extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $otp,
        public int $ttlMinutes
    ) {
        $this->subject('Votre code OTP');
    }

    public function build(): static
    {
        return $this->view('emails.foreigner.otp')
            ->with(['otp' => $this->otp, 'ttl' => $this->ttlMinutes]);
    }
}
