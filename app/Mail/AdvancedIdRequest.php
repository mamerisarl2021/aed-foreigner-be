<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class AdvancedIdRequest extends Mailable implements ShouldQueue
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
            ->subject('Demande d\'une identité de type avancée.')->view('emails.advanced_id_request')->with([
                'user' => $this->user
            ]);
    }
}
