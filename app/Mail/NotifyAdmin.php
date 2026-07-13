<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class NotifyAdmin extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public $code;

    public $email;

    public function __construct($code, $email)
    {
        $this->code = $code;
        $this->email = $email;
    }

    public function build()
    {
        return $this->from('collabone@qualitycorporate.com')
            ->subject('Demande du code OTP')->view('emails.notify_admin')->with([
                'code' => $this->code,
                'email' => $this->email,
            ]);
    }
}
