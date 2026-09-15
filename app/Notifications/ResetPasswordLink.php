<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Notifications\Concerns\BuildsPasswordResetLink;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * "Olvidé mi contraseña" — sent when someone who already has an account asks
 * for a new password from /forgot-password.
 *
 * Replaces Illuminate\Auth\Notifications\ResetPassword, which is in English
 * and signs off as a generic Laravel app. Wired up in User::sendPasswordReset-
 * Notification(). The first-time invitation is a different message carrying
 * the same link — see ClientInvitation.
 */
class ResetPasswordLink extends Notification
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
            ->subject('Restablece tu contraseña — '.config('app.name'))
            ->greeting("Hola, {$notifiable->name}.")
            ->line('Recibimos una solicitud para cambiar la contraseña de tu cuenta.')
            ->action('Crear contraseña nueva', $this->resetUrl($notifiable, $this->token))
            ->line('El enlace vence en '.$this->linkLifetime().'.')
            ->line('Si no fuiste tú, ignora este correo: tu contraseña sigue siendo la misma.')
            ->salutation('— El equipo de '.config('app.name'));
    }
}
