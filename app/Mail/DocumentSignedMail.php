<?php

namespace App\Mail;

use App\Models\Signature;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class DocumentSignedMail extends Mailable
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
            subject: 'Document signé: '.$this->documentTitle
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.document-signed',
            with: [
                'documentTitle' => $this->documentTitle,
                'signerName' => $this->signature->user->name,
            ]
        );
    }
}
