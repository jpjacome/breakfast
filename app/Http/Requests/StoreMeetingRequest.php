<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Scheduling or editing a meeting.
 *
 * The two notification channels are separate booleans rather than one "avisar"
 * flag: a meeting moved by ten minutes rarely deserves an email and a first
 * invitation always does, and the admin is the one who knows which this is.
 */
class StoreMeetingRequest extends FormRequest
{
    /** The route is already behind auth, 'breakfast' and 'covers-client'. */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:160'],
            'scheduled_at' => ['required', 'date'],
            'link' => ['nullable', 'url', 'max:500'],
            'agenda' => ['nullable', 'string', 'max:5000'],
            'notes' => ['nullable', 'string', 'max:5000'],

            'notify_portal' => ['nullable', 'boolean'],
            'notify_mail' => ['nullable', 'boolean'],
        ];
    }

    public function attributes(): array
    {
        return [
            'title' => 'título',
            'scheduled_at' => 'fecha y hora',
            'link' => 'link',
            'agenda' => 'agenda',
            'notes' => 'notas',
        ];
    }

    public function messages(): array
    {
        return [
            'link.url' => 'El link tiene que ser una dirección completa, con https://.',
        ];
    }

    /** The meeting's own columns. */
    public function meetingAttributes(): array
    {
        return [
            'title' => trim($this->validated('title')),
            'scheduled_at' => $this->date('scheduled_at'),
            'link' => trim((string) $this->validated('link')) ?: null,
            'agenda' => trim((string) $this->validated('agenda')) ?: null,
            'notes' => trim((string) $this->validated('notes')) ?: null,
        ];
    }

    /**
     * Which channels to announce on.
     *
     * Unticked means unticked: an unchecked box posts nothing, so absence has
     * to read as "no", never as "unspecified, use the default". Getting that
     * backwards would mail a brand every time somebody fixed a typo.
     *
     * @return array<int, string>
     */
    public function channels(): array
    {
        return array_values(array_filter([
            $this->boolean('notify_portal') ? 'database' : null,
            $this->boolean('notify_mail') ? 'mail' : null,
        ]));
    }
}
