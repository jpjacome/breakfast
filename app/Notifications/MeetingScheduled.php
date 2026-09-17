<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\Meeting;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * A meeting was scheduled, moved, or called off.
 *
 * NOT ShouldQueue, deliberately. Nothing drains the queue on this host, so a
 * queued notification is one that never happens. See CLAUDE.md §3.
 *
 * The channels are chosen per send rather than fixed here: the admin ticks
 * "avisar en el portal" and "enviar correo" independently when they create the
 * meeting, because a meeting moved by ten minutes rarely deserves an email and
 * a first invitation always does.
 */
class MeetingScheduled extends Notification
{
    public const CREATED = 'creada';

    public const MOVED = 'movida';

    public const CANCELLED = 'cancelada';

    /**
     * @param  array<int, string>  $channels  'mail' and/or 'database'.
     */
    public function __construct(
        public Meeting $meeting,
        public string $event = self::CREATED,
        public array $channels = ['database', 'mail'],
    ) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return $this->channels;
    }

    /**
     * The portal inbox row.
     *
     * Everything the bell needs to render a line is stored here rather than
     * looked up later: a notification about a meeting that has since been
     * deleted should still read as a sentence, not blow up on a null.
     *
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'reunion',
            'event' => $this->event,
            'meeting_id' => $this->meeting->id,
            'title' => $this->meeting->title,
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

        // The join link is the point of the mail when there is one, so it is
        // the button. Without one the button goes to the portal, where the
        // link will appear once somebody adds it.
        if ($this->meeting->link && $this->event !== self::CANCELLED) {
            $mail->action('Unirse a la reunión', $this->meeting->link);
        } elseif ($this->event !== self::CANCELLED) {
            $mail->action('Ver en el portal', route('portal.reuniones'));
        }

        return $mail;
    }

    private function subject(): string
    {
        $brand = $this->meeting->client->name;

        return match ($this->event) {
            self::MOVED => "Cambió la fecha de una reunión · {$brand}",
            self::CANCELLED => "Se canceló una reunión · {$brand}",
            default => "Nueva reunión · {$brand}",
        };
    }

    private function headline(): string
    {
        return match ($this->event) {
            self::MOVED => "Movimos «{$this->meeting->title}» a una fecha nueva.",
            self::CANCELLED => "Cancelamos «{$this->meeting->title}».",
            default => "Agendamos «{$this->meeting->title}».",
        };
    }
}
