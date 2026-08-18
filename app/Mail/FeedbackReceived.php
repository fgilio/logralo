<?php

declare(strict_types=1);

namespace App\Mail;

use App\Models\Feedback;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * The nudge that something came in.
 *
 * Plain text on purpose: it carries one paragraph somebody typed on a phone,
 * and a branded HTML shell around it would be four times the size of the
 * message. The reply-to is the member, so answering is a reply.
 */
final class FeedbackReceived extends Mailable
{
    public function __construct(public readonly Feedback $feedback) {}

    public function envelope(): Envelope
    {
        $member = $this->feedback->user;

        return new Envelope(
            replyTo: [new Address($member->email, $member->name)],
            subject: "Logralo: {$member->name} escribió",
        );
    }

    public function content(): Content
    {
        return new Content(text: 'mail.feedback');
    }
}
