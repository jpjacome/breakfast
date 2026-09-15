<?php

declare(strict_types=1);

namespace App\Services\Ai;

use App\Models\AssistantMessage;
use App\Models\Client;
use App\Models\User;
use App\Services\Ai\Admin\AdminContextBuilder;
use App\Services\Ai\Concerns\FinishesTruncatedAnswers;
use App\Services\Ai\Contracts\LlmClient;
use App\Services\Ai\Data\LlmResponse;
use App\Services\Ai\Data\Message;
use App\Services\Ai\Exceptions\LlmException;

/**
 * The assistant on the Breakfast dashboard. The only public face of the
 * admin-side AI — call this, not the builder or the client.
 *
 * Same engine as BrandAssistant, opposite context. That one reads one brand's
 * entregables and documents; this one reads the state of every brand, from the
 * database. The two have different risks and therefore different defences:
 * the client assistant must not invent brand attributes, this one must not
 * invent figures and dates.
 *
 * READ-ONLY BY CONSTRUCTION. There is no write path in this class or anything
 * it calls. Creating brands, moving steps and sending invitations live on
 * their own screens, where somebody sees exactly what is about to happen
 * before confirming it — a chat that executes changes is the fastest way to
 * move the wrong client's step.
 */
final class AdminAssistant
{
    use FinishesTruncatedAnswers;

    public function __construct(
        private readonly LlmClient $client,
        private readonly AdminContextBuilder $builder,
        private readonly UsageRecorder $usage,
    ) {}

    /**
     * Answer one question about the portfolio, or about one brand in it.
     *
     * $client is the dropdown: null means "todas las marcas", which is the
     * default and the mode most questions arrive in.
     *
     * @throws LlmException
     */
    public function answer(
        User $user,
        string $question,
        ?Client $client = null,
        array $attachments = [],
        array $attachmentNames = [],
        array $attachmentIds = [],
    ): LlmResponse {
        $messages = $this->builder->forQuestion(
            $user,
            $question,
            $client,
            $this->history($user),
            $attachments,
        );

        // Reading a table and reporting what is in it, not writing brand copy.
        // The house default of 0.8 invents here, and inventing is this
        // assistant's whole risk.
        $options = ['temperature' => 0.2];

        try {
            // finished(): the comparison that stopped after its own preamble in
            // the beta review. If the answer was cut off at the token ceiling,
            // one more call finishes it. See the trait.
            $response = $this->finished(
                $messages,
                $this->client->complete($messages, role: 'content', options: $options),
                role: 'content',
                options: $options,
            );
        } catch (LlmException $e) {
            $this->usage->recordFailure(
                $e,
                clientId: $client?->id,
                userId: $user->id,
                operation: 'admin-question',
                model: $this->client->modelFor('content'),
            );

            throw $e;
        }

        // Logged against the brand the DROPDOWN named, so per-brand spend stays
        // honest — otherwise asking about one brand twenty times would look
        // like Breakfast's own overhead.
        //
        // A brand named only in the question text still gets its ficha (see
        // MentionedBrands) but not the charge: resolving mentions here as well
        // as in the builder would mean two identical queries on every turn, and
        // spend attribution is not worth that on a screen this hot.
        $this->usage->record(
            $response,
            clientId: $client?->id,
            userId: $user->id,
            operation: 'admin-question',
        );

        $this->remember($user, $question, $response->content, $client, $attachmentNames, $attachmentIds);

        return $response;
    }

    /**
     * This person's own last few turns, oldest first.
     *
     * ⚠️ THEIRS ALONE. AssistantMessage::thread() takes a user id and nothing
     * else, so there is no path here that reads another person's conversation —
     * an admin cannot ask what a client discussed, because that thread is never
     * fetched.
     *
     * @return array<int, Message>
     */
    private function history(User $user): array
    {
        return AssistantMessage::query()
            ->thread($user->id, AssistantMessage::SURFACE_ADMIN)
            ->get()
            ->reverse()
            ->map(fn (AssistantMessage $m): Message => $m->toLlmMessage())
            ->values()
            ->all();
    }

    /**
     * Both halves of the exchange, written after the answer is in hand.
     *
     * After, so a failed call leaves no half-turn behind: a question with no
     * answer replayed into the next request reads as the assistant having
     * ignored somebody.
     */
    private function remember(
        User $user,
        string $question,
        string $answer,
        ?Client $client,
        array $files = [],
        array $assetIds = [],
    ): void {
        foreach ([['user', $question], ['assistant', $answer]] as [$role, $body]) {
            AssistantMessage::create([
                'user_id' => $user->id,
                'surface' => AssistantMessage::SURFACE_ADMIN,
                'role' => $role,
                'body' => $body,
                'client_id' => $client?->id,
                // Names, never bytes: the transcript has to read right on
                // reload without a voice note living in a text column.
                'attachments' => $role === 'user' && $files !== [] ? $files : null,
                // Names for the model, ids for the screen — see the
                // attachment_ids migration. Only the user's half carries files.
                'attachment_ids' => $role === 'user' && $assetIds !== [] ? $assetIds : null,
            ]);
        }
    }
}
