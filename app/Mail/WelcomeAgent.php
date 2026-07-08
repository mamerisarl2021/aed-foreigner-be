<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class WelcomeAgent extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public $user;

    public $link;

    public function __construct($user, $link)
    {
        $this->user = $user;
        $this->link = $link;
    }

    public function build()
    {
        return $this->from('collabone@qualitycorporate.com')
            ->subject('Ajout d\'un compte agent')->view('emails.agent_added_to_aed')->with([
                'user' => $this->user,
                'link' => $this->link,
            ]);
    }
}
