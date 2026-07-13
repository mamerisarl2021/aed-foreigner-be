<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class PlanifiedEmail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public $user;

    public function __construct($user)
    {
        $this->user = $user;
    }

    public function build()
    {
        return $this->from('collabone@qualitycorporate.com')
            ->subject('Planification d\'une rencontre en face à face.')->view('emails.planned')->with([
                'user' => $this->user,
            ]);
    }
}
