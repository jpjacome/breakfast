<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\BrandInvitation;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Somebody wants to add your existing account to their brand — queued item B.
 *
 * ⚠️ NOT `ClientInvitation`, and the difference is the whole point. That one
 * goes to a person who has no account: it says "we made you one, pick a
 * password", and reading the mailbox is itself the consent. This goes to
 * somebody who has been signing in for months, about a brand they never chose,
 * and the only thing it may do is ASK.
 *
 * ⚠️ IT NAMES WHO IS INVITING. A bare "join this brand" from nobody reads as
 * phishing, and this is a mail with a link in it asking for a decision — which
 * is exactly the shape people are taught to distrust.
 *
 * ⚠️ MAIL AND THE PORTAL INBOX BOTH. A person who is already using the app may
 * well see the bell before the mail, and an invitation sitting only in an inbox
 * they read on Fridays is an invitation that expires.
 */
class BrandMembershipInvitation extends Notification
{
    public function __construct(public BrandInvitation $invitation) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $brand = $this->invitation->client->name;

        return (new MailMessage)
            ->subject("Te invitaron a {$brand} en ".config('app.name'))
            // ⚠️ ITS OWN TEMPLATE. mail.invitation says "pick a password",
            // which is the wrong thing to say to somebody who has had one for
            // months — see that file's own note.
            ->markdown('mail.brand-invitation', [
                'name' => $notifiable->name,
                'intro' => $this->intro(),
                'actionUrl' => route('portal.invitaciones.show', $this->invitation->token),
                'expiresIn' => BrandInvitation::LIFETIME_DAYS.' días',
            ]);
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'headline' => 'Te invitaron a '.$this->invitation->client->name,
            'body' => $this->intro(),
            'url' => route('portal.invitaciones.show', $this->invitation->token),
            'invitation_id' => $this->invitation->id,
        ];
    }

    private function intro(): string
    {
        $who = $this->invitation->inviter?->name;
        $brand = $this->invitation->client->name;

        return $who === null
            ? "Te invitaron a unirte a la marca {$brand}."
            : "{$who} te invitó a unirte a la marca {$brand}.";
    }
}
