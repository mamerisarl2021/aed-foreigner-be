<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class AdvancedIdMid extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;
    public $email;
    public $link;
    public $name;

    public function __construct($email, $name, $link)
    {
        $this->email = $email;
        $this->name = $name;
        $this->link = $link;
    }

    public function build()
    {
        return  $this->from('collabone@qualitycorporate.com')
            ->subject('Paiement enregistré.')->view('emails.advanced_id_mid')->with([
                'link' => $this->link,
                'name' => $this->name,
                'email' => $this->email
            ]);
    }
}
