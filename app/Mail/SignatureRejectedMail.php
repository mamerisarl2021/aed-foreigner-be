<?php

namespace App\Mail;

use App\Models\Signature;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class SignatureRejectedMail extends Mailable
{
    use Queueable, SerializesModels;

    public $signature;

    public $documentTitle;

    public function __construct(Signature $signature)
    {
        $this->signature = $signature;
        $this->documentTitle = $signature->document->title;
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Document refusé: '.$this->documentTitle
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.signature-rejected',
            with: [
                'documentTitle' => $this->documentTitle,
                'rejecterName' => $this->signature->user->name,
            ]
        );
    }
}
