<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Notifications\Concerns\BuildsPasswordResetLink;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * The welcome mail for someone joining Breakfast itself.
 *
 * Same mechanism as ClientInvitation — a password-reset token behind wording
 * that says what actually happened — but a different message: this person is
 * being handed the back-office, not a brand's portal.
 */
class StaffInvitation extends Notification
{
    use BuildsPasswordResetLink;

    public function __construct(public string $token) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Tu acceso al back-office de '.config('app.name'))
            ->markdown('mail.invitation', [
                'name' => $notifiable->name,
                'intro' => 'Te dimos de alta en el equipo de '.config('app.name').'. '.
                           'Tu cuenta es del back-office, no del portal de un cliente.',
                'actionUrl' => $this->resetUrl($notifiable, $this->token),
                'expiresIn' => $this->linkLifetime(),

                // No mention of which brands: an Equipo account is scoped to
                // the ones it was put on, and that list can change before this
                // person even reads the mail. See EnsureStaffCoversClient.
                'highlights' => [
                    'Las marcas que te asignen, con todo su contexto.',
                    'El asistente de IA, sobre una marca o sobre todas.',
                    'El material que subimos para que la IA aprenda cada marca.',
                ],
            ]);
    }
}
