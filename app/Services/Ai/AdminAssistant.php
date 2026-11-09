<?php

declare(strict_types=1);

namespace App\Services\Ai;

use App\Models\AssistantMessage;
use App\Models\Client;
use App\Models\Conversation;
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
        private readonly SummarisesConversations $summariser,
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
        ?Conversation $conversation = null,
        ?string $references = null,
    ): LlmResponse {
        /*
         * ⚠️ THE REFERENCED CONVERSATIONS RIDE ON THE QUESTION AND ARE NOT
         * REMEMBERED WITH IT. remember() below is given the RAW question, so
         * the transcript shows what the person typed — "retoma #conv-12" —
         * rather than four thousand characters of another conversation they
         * never wrote. Storing the digest would also replay it verbatim on
         * every later turn, paying for the same block again and again.
         */
        $messages = $this->builder->forQuestion(
            $user,
            $question.($references ?? ''),
            $client,
            $this->history($user, $conversation),
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

        $this->remember(
            $user,
            $question,
            $response->content,
            $client,
            $attachmentNames,
            $attachmentIds,
            $conversation,
        );

        /*
         * ⚠️ AFTER THE ANSWER, NEVER BEFORE, AND IT NEVER THROWS. Folding the
         * old part up is a second paid call; doing it first would make a person
         * wait twice for one question, and a provider hiccup in it would turn
         * an answer they have already been charged for into a 500. A
         * conversation that fails to fold simply stays long and tries again on
         * the next turn — see SummarisesConversations.
         */
        if ($conversation !== null) {
            $conversation->noteActivity();
            $this->summariser->handle($conversation);
        }

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
    private function history(User $user, ?Conversation $conversation = null): array
    {
        /*
         * ⚠️ THE CONVERSATION DECIDES WHAT IS REPLAYED, not a count of rows.
         * scopeThread() returns this person's last twenty turns whatever
         * conversation they belong to, which is what item 5 objects to: opening
         * a new conversation used to carry the previous one's turns into it,
         * and past twenty the beginning was dropped with nothing said.
         * replayable() returns everything since the summary, and the summary
         * carries the rest — so nothing is forgotten, it is carried
         * differently.
         *
         * The old path stays for a turn that arrives with no conversation,
         * which is every turn from a page that has not been reloaded since the
         * panel shipped.
         */
        $turns = $conversation !== null
            ? $conversation->replayable()
            : AssistantMessage::query()
                ->thread($user->id, AssistantMessage::SURFACE_ADMIN)
                ->get()
                ->reverse();

        $history = $turns
            ->map(fn (AssistantMessage $m): Message => $m->toLlmMessage())
            ->values()
            ->all();

        // The folded-up part, ahead of the turns it precedes. It is the model's
        // memory of the beginning and reads as one more thing that was said.
        $summary = trim((string) $conversation?->summary);

        return $summary === ''
            ? $history
            : [Message::user('Resumen de lo anterior en esta conversación:'.'

'.$summary), ...$history];
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
        ?Conversation $conversation = null,
    ): void {
        $conversation?->titleFrom($question);

        foreach ([['user', $question], ['assistant', $answer]] as [$role, $body]) {
            AssistantMessage::create([
                // Which conversation this turn belongs to — item 5. Null only
                // for a turn posted by a page that predates the panel.
                'conversation_id' => $conversation?->id,
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
