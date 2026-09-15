<?php

declare(strict_types=1);

namespace App\Services\Ai\Data;

/**
 * One chat message. Deliberately provider-neutral: DeepSeek, OpenRouter and
 * OpenAI all take this shape, so a future provider swap needs no changes here.
 *
 * Content is either a plain string or a list of content parts. The string form
 * is kept for every message that is only text — it is what the providers
 * themselves send back, it is what text-only providers accept, and it keeps
 * the cacheable prefix byte-identical to what it was before attachments
 * existed. Parts appear only where a file is genuinely attached.
 */
final readonly class Message
{
    /**
     * @param  string|array<int, array<string, mixed>>  $content
     */
    private function __construct(
        public string $role,
        public string|array $content,
    ) {}

    public static function system(string $content): self
    {
        return new self('system', $content);
    }

    /**
     * A system block marked as worth caching.
     *
     * DeepSeek caches on a matching prefix with nothing to mark. Gemini does
     * not: it wants an explicit breakpoint, and without one a block that is
     * byte-identical on every single request is paid for in full every single
     * time. Marking it turns those tokens into cache reads at 0.25x.
     *
     * Only worth putting on something large and genuinely stable — the schema
     * block, not the conversation. Flash-class models ignore anything under
     * 1,024 tokens anyway.
     */
    public static function cacheableSystem(string $content): self
    {
        if (! config('ai.providers.'.config('ai.provider').'.cache_breakpoints')) {
            return self::system($content);
        }

        return new self('system', [[
            'type' => 'text',
            'text' => $content,
            'cache_control' => ['type' => 'ephemeral'],
        ]]);
    }

    public static function user(string $content): self
    {
        return new self('user', $content);
    }

    public static function assistant(string $content): self
    {
        return new self('assistant', $content);
    }

    /**
     * A user turn carrying files: the brandbook, a screenshot, a photo.
     *
     * The text part goes first on purpose. A model reading parts in order does
     * better when it knows what it is being asked before it starts looking at
     * a forty-page deck.
     *
     * @param  array<int, Attachment>  $attachments
     */
    public static function userWithAttachments(string $content, array $attachments): self
    {
        if ($attachments === []) {
            return self::user($content);
        }

        return new self('user', [
            ['type' => 'text', 'text' => trim($content)],
            ...array_map(
                static fn (Attachment $attachment): array => $attachment->toContentPart(),
                array_values($attachments),
            ),
        ]);
    }

    public function hasAttachments(): bool
    {
        return is_array($this->content);
    }

    /**
     * The readable text of the message, with any files reduced to a mention.
     *
     * For logs, for transcripts, and for the cache fingerprint — anywhere the
     * message needs to be a string and a megabyte of base64 would be noise.
     */
    public function text(): string
    {
        if (is_string($this->content)) {
            return $this->content;
        }

        $pieces = [];

        foreach ($this->content as $part) {
            $pieces[] = match ($part['type'] ?? '') {
                'text' => (string) ($part['text'] ?? ''),
                'image_url' => '[imagen adjunta]',
                'input_audio' => '[audio adjunto]',
                'file' => '[archivo adjunto: '.($part['file']['filename'] ?? 'sin nombre').']',
                default => '',
            };
        }

        return trim(implode("\n", array_filter($pieces)));
    }

    /**
     * @return array{role: string, content: string|array<int, array<string, mixed>>}
     */
    public function toArray(): array
    {
        return ['role' => $this->role, 'content' => $this->content];
    }
}
