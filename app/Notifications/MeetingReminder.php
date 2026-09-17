<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Enums\ReminderWindow;
use App\Models\Meeting;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * "Your meeting is coming up" — SEG-01 of the beta review.
 *
 * SEPARATE FROM MeetingScheduled, which announces a CHANGE: created, moved,
 * cancelled. This announces nothing new — the meeting is exactly where it was —
 * and only says that it is close. Folding the two together would mean a class
 * whose subject line is a five-branch match on an event that is sometimes a
 * change and sometimes a countdown, and every future edit to one would have to
 * be read against the other.
 *
 * Both still go out through NotifyAboutMeeting, which remains the only place
 * that decides who hears about a meeting.
 *
 * NOT ShouldQueue. Nothing drains the queue on this host, so a queued
 * notification is one that never happens. See CLAUDE.md §3.
 *
 * ⚠️ EVERY NOTICE NAMES THE BRAND, and the report asked for it explicitly:
 * "cada aviso debe identificar claramente la marca, la reunión, la fecha y la
 * hora". It matters more here than anywhere else in the app, because the same
 * address can be on several brands and a bare "tienes reunión mañana" is then
 * a question rather than a reminder.
 */
class MeetingReminder extends Notification
{
    public function __construct(
        public Meeting $meeting,
        public ReminderWindow $window,
    ) {}

    /**
     * Both channels, always.
     *
     * Unlike MeetingScheduled, whose channels an admin ticks per meeting: a
     * reminder nobody chose to send is not worth a choice, and the report asks
     * for both — "mostrar el recordatorio dentro de la plataforma y enviarlo
     * también por correo".
     *
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    /**
     * The portal inbox row.
     *
     * Self-contained, like MeetingScheduled's: a bell that renders from stored
     * text keeps reading correctly after the meeting it refers to is gone.
     *
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'recordatorio',
            'window' => $this->window->value,
            'meeting_id' => $this->meeting->id,
            'title' => $this->meeting->title,
            'brand' => $this->meeting->client->name,
            'when' => $this->meeting->whenInWords(),
            'scheduled_at' => $this->meeting->scheduled_at->toIso8601String(),
            'headline' => $this->headline(),
            // ⚠️ THE MEETING, NOT THE LIST. This was route('portal.reuniones')
            // until 2026-09-17 while meeting_id sat unused two lines above, so
            // a reminder opened a page whose top half was a different meeting.
            // That route also switches the active brand — item 11.
            'url' => route('portal.reunion', $this->meeting),
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->subject($this->subject())
            ->greeting("Hola, {$notifiable->name}.")
            ->line($this->headline())
            ->line('**'.ucfirst($this->meeting->whenInWords()).'**');

        if ($this->meeting->agenda) {
            $mail->line('Agenda: '.$this->meeting->agenda);
        }

        // On the one-hour notice the join link IS the message: somebody is
        // about to look for it.
        if ($this->meeting->link) {
            $mail->action('Unirse a la reunión', $this->meeting->link);
        } else {
            $mail->action('Ver en el portal', route('portal.reuniones'));
        }

        return $mail;
    }

    /** Brand first: the same person may be on several. */
    private function subject(): string
    {
        return match ($this->window) {
            ReminderWindow::Semana => "Reunión esta semana · {$this->meeting->client->name}",
            ReminderWindow::Dia => "Reunión mañana · {$this->meeting->client->name}",
            ReminderWindow::Hora => "Reunión en una hora · {$this->meeting->client->name}",
        };
    }

    private function headline(): string
    {
        return $this->window->sentence()." Es «{$this->meeting->title}».";
    }
}
