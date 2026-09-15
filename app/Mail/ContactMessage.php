<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class ContactMessage extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * @param  array{first_name: string, last_name: string, email: string, phone: string, about_brand: ?string}  $submission
     */
    public function __construct(public array $submission) {}

    public function envelope(): Envelope
    {
        $name = "{$this->submission['first_name']} {$this->submission['last_name']}";

        return new Envelope(
            subject: "Nueva sesión solicitada — {$name}",
            // From stays the app's own address so the message passes SPF; the
            // visitor's address goes in Reply-To, where replying actually works.
            replyTo: [$this->submission['email']],
        );
    }

    public function content(): Content
    {
        // Plain text: the body is five labelled lines, and an HTML wrapper
        // would add nothing anyone reading it needs.
        return new Content(text: 'mail.contact-message');
    }
}
