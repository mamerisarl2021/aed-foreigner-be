<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class RevocatedEmail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public $user;

    public function __construct($user)
    {
        $this->user = $user;
    }

    public function build()
    {
        return  $this->from('collabone@qualitycorporate.com')
            ->subject('Demande révocation traitée.')->view('emails.revocated')->with([
                'user' => $this->user
            ]);
    }
}
