<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\Client;
use App\Notifications\Concerns\BuildsPasswordResetLink;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * The first mail a client's user ever gets: their account exists, here is how
 * to pick a password and get in.
 *
 * It carries a password-reset token because that is the mechanism, but it is
 * not a "you asked to reset your password" message — the recipient never asked
 * for anything and has no password to reset. Saying so plainly is the whole
 * reason this is separate from ResetPasswordLink.
 */
class ClientInvitation extends Notification
{
    use BuildsPasswordResetLink;

    public function __construct(
        public string $token,
        public Client $client,
    ) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Tu acceso al portal de '.config('app.name'))
            ->markdown('mail.invitation', [
                'name' => $notifiable->name,
                'intro' => "Creamos tu cuenta en el portal de {$this->client->name}, ".
                           'donde vive todo lo que hacemos juntos.',
                'actionUrl' => $this->resetUrl($notifiable, $this->token),
                'expiresIn' => $this->linkLifetime(),

                // Deliberately generic. What this person actually sees is
                // decided by their permission map, and a mail promising a
                // section they were not granted is worse than no list at all.
                'highlights' => [
                    'Tu marca: estrategia, identidad y todo el material que la define.',
                    'El asistente de IA, que responde ya sabiendo cómo habla tu marca.',
                    'Lo que estamos produciendo y en qué va.',
                ],
            ]);
    }
}
