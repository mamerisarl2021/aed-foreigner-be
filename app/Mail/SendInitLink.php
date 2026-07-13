<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class SendInitLink extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public $code;

    public $email;

    public $type;

    public function __construct($code, $email, $type)
    {
        $this->code = $code;
        $this->email = $email;
        $this->type = $type;
    }

    public function build()
    {
        return $this->from('collabone@qualitycorporate.com')
            ->subject('Demande du lien de mise à jour')->view('emails.send_init_link')->with([
                'code' => $this->code,
                'email' => $this->email,
                'type' => $this->type,
            ]);
    }
}
