<?php

namespace App\Mail;

use App\Models\Signature;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class SignatureInvitationMail extends Mailable
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
            subject: 'Invitation de signature pour ' . $this->documentTitle
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.signature-invitation',
            with: [
                'documentTitle' => $this->documentTitle,
                'inviterName' => $this->signature->document->user->name,
                'signatureLink' => env('FRONTEND_URL')."/backoffice/client/received-documents"
            ]
        );
    }
}
