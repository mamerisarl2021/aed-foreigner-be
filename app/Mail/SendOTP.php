<?php

declare(strict_types=1);

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class SendOTP extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public mixed $code,
        public string $email,
    ) {}

    public function build(): static
    {
        return $this->from('collabone@qualitycorporate.com')
            ->subject('Demande du code OTP')->view('emails.send_otp')->with([
                'code' => $this->code,
                'email' => $this->email,
            ]);
    }
}
