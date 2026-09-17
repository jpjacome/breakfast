<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Actions\KeepAssistantAttachments;
use App\Actions\StartBrandDraft;
use App\Http\Controllers\Controller;
use App\Http\Requests\BrandOnboardingTurnRequest;
use App\Models\BrandDeliverables;
use App\Models\BrandOnboardingMessage;
use App\Models\Client;
use App\Services\Ai\AssistantFailure;
use App\Services\Ai\Brand\DeliverableExtractor;
use App\Services\Ai\Brand\ProposalReconciler;
use App\Services\Ai\Data\Attachment;
use App\Services\Ai\Data\ExtractionResult;
use App\Services\Ai\Data\Message;
use App\Services\Ai\Exceptions\LlmException;
use Illuminate\Http\JsonResponse;

/**
 * The assistant beside the entregables board.
 *
 * It reads what the team hands it — brandbooks, briefs, workshop notes, voice
 * notes — and proposes content for the 48 entregables. The thread lives in
 * brand_onboarding_messages.
 *
 * IT NEVER WRITES brand_deliverables. Every proposal is a card on screen that
 * a person applies into a textarea, and the board is then saved by that same
 * person through ClientProcessController::update(). There is no path where the
 * model's reading reaches the database on its own — which is exactly why the
 * table has no provenance column to record one.
 *
 * TWO ENTRY POINTS, ONE BEHAVIOUR. store() runs it beside the board of a brand
 * that exists; draft() runs it on /clientes/nueva, where the brand is created
 * the moment there is something to attach. Everything after that point is the
 * same code — the screen a person is standing on decides what the assistant is
 * for, not which assistant they get.
 */
class BrandOnboardingController extends Controller
{
    public function __construct(
        private readonly DeliverableExtractor $extractor,
        private readonly ProposalReconciler $reconciler,
        private readonly KeepAssistantAttachments $keep,
    ) {}

    public function store(BrandOnboardingTurnRequest $request, Client $client): JsonResponse
    {
        return $this->turn($request, $client);
    }

    /**
     * The same assistant, on /clientes/nueva, where the brand may not exist.
     *
     * A file or a proposal needs somewhere to go, so the first turn that
     * carries either brings the draft row into being — see StartBrandDraft,
     * the only place that happens.
     *
     * ⚠️ TYPING IS CONTENT TOO. This used to create a row only for a file or a
     * filled form field, and answered everything else with a fixed sentence
     * asking for material — without ever calling the model. So "empecemos una
     * marca nueva, se llama Patito" got the same canned request for a
     * brandbook, twice, and the name the person had just given was never read
     * by anything. The screen looked broken because it was.
     *
     * Now a typed message reaches the model even with no row, and what comes
     * back decides whether there is anything to save: a stated brand name or a
     * proposal creates the draft; "hola" still does not. The trigger is still
     * content — the model is just what recognises it, which is the one thing on
     * this screen that can.
     */
    public function draft(BrandOnboardingTurnRequest $request, StartBrandDraft $drafts): JsonResponse
    {
        $existing = $request->existingDraft();

        // Files are content. A turn that carries one has something to attach,
        // even when the brand form beside it is still blank.
        $client = $drafts->resolve(
            $request->user(),
            $existing,
            $request->draftAttributes(),
            force: $request->hasFile('files'),
        );

        if ($client !== null) {
            return $this->turn($request, $client);
        }

        // No row, so this is a typed message with nothing attached yet — a turn
        // with neither text nor a file never gets here, BrandOnboardingTurnRequest
        // rejects it, and a file would have forced the row above. There is
        // therefore nothing to answer with a canned string: the model reads it.
        return $this->firstTurnWithoutARow($request, $drafts);
    }

    /**
     * Somebody typed something before there is a brand to attach it to.
     *
     * Run the model with no client — clientId is nullable on the extractor, so
     * the turn is still costed and still logged, just not against a brand that
     * does not exist yet. Then create the draft only if the reading found
     * something worth a row.
     *
     * ⚠️ THE ROW IS CREATED FROM THE FORM, NEVER FROM THE READING. It briefly
     * was: the draft came out named "Patito" the moment the model heard the
     * name, which put the model's reading in the database with nobody having
     * agreed to it, and left the row and the visible form saying two different
     * things — the brand list said Patito while the Nombre field was still
     * empty. A tester read that, correctly, as the approval not working.
     *
     * So the name arrives the same way every other reading does: as a card,
     * which fills the field when clicked, and the autosave then carries it to
     * the row and renames the slug with it. Until somebody clicks, the draft is
     * "Marca sin nombre", which is exactly what that placeholder is for.
     */
    private function firstTurnWithoutARow(
        BrandOnboardingTurnRequest $request,
        StartBrandDraft $drafts,
    ): JsonResponse {
        try {
            $result = $this->extractor->turn(
                brandName: StartBrandDraft::PLACEHOLDER_NAME,
                deliverables: new BrandDeliverables,
                message: $this->said($request),
                formValues: $request->formValues(),
                userId: $request->user()->id,
            );
        } catch (LlmException $e) {
            report($e);

            return response()->json(
                ['error' => AssistantFailure::message($e, forStaff: true)],
                502,
            );
        }

        // Nothing to file yet. Answer and leave the brand list alone —
        // abandoned drafts are a real cost, which is the whole reason this
        // screen does not create a row the moment it opens.
        if ($result->brand === [] && ! $result->hasProposals()) {
            return response()->json([
                'reply' => $result->reply,
                'questions' => $result->questions,
                'proposals' => [],
                'brand' => [],
                'draft' => null,
            ]);
        }

        $client = $drafts->resolve(
            $request->user(),
            null,
            $request->draftAttributes(),
            force: true,
        );

        $cards = $this->reconciler->reconcile(
            $client->deliverablesOrNew(),
            $result->proposals,
            $request->formValues(),
        );

        $this->recordUserTurn($client, $request->user()->id, $request, []);

        BrandOnboardingMessage::create([
            'client_id' => $client->id,
            'role' => 'assistant',
            'body' => $result->reply,
            'proposals' => $cards,
            'questions' => $result->questions,
        ]);

        return $this->reply($result, $cards, $client);
    }

    /**
     * One turn, in whichever of its three shapes this request is.
     *
     * ⚠️ A TURN CARRYING FILES IS NOT ONE REQUEST ANY MORE. Reading a brandbook
     * and writing forty-eight proposals in a single generation runs 60–150
     * seconds; this host kills a request at ~180s, and long requests starve the
     * worker pool badly enough that the PUBLIC SITE answers 503 behind them.
     * Measured 2026-08-18, and invisible from inside: the worker dies before
     * Laravel's handler runs, so nothing reaches laravel.log.
     *
     * So an upload is a read followed by four proposal calls the browser makes
     * in turn, each one short. A typed message stays exactly as it was — it was
     * never the problem, and making a two-word question cost five round trips
     * would be a worse app to pay for a bug it does not have.
     */
    private function turn(BrandOnboardingTurnRequest $request, Client $client): JsonResponse
    {
        if ($request->isProposalBatch()) {
            return $this->propose($request, $client);
        }

        if ($request->hasFile('files')) {
            return $this->read($request, $client);
        }

        return $this->converse($request, $client);
    }

    /**
     * PHASE ONE: read the files, keep the reading, propose nothing yet.
     *
     * What is kept is a digest on the brand, and it is not only a way of making
     * the request smaller: attachments ride the turn that carried them and
     * `brand_onboarding_messages.attachments` stores filenames, never bytes, so
     * until now the model read a brandbook once and answered every later
     * question about it from nothing but the file's name.
     */
    private function read(BrandOnboardingTurnRequest $request, Client $client): JsonResponse
    {
        $attachments = $this->attachmentsOrFail($request);

        if ($attachments instanceof JsonResponse) {
            return $attachments;
        }

        try {
            $reading = $this->extractor->read(
                brandName: $client->name,
                message: $this->said($request),
                attachments: $attachments,
                existingDigest: $client->document_digest,
                clientId: $client->id,
                userId: $request->user()->id,
            );
        } catch (LlmException $e) {
            // Their own turn survives a failed reply: they typed it, and losing
            // it because the provider was down is the rudest possible outcome.
            $this->recordUserTurn($client, $request->user()->id, $request, $attachments);

            report($e);

            return response()->json(
                ['error' => AssistantFailure::message($e, forStaff: true)],
                502,
            );
        }

        if (! $reading->isEmpty()) {
            $client->update([
                'document_digest' => $this->appendDigest(
                    $client,
                    $reading->digest,
                    $attachments,
                    $reading->visual,
                ),
            ]);
        }

        $this->recordUserTurn($client, $request->user()->id, $request, $attachments);

        // Kept, so the batches below have a row to write their cards onto. The
        // id goes to the browser and comes back with each batch — see propose().
        $announcement = BrandOnboardingMessage::create([
            'client_id' => $client->id,
            'role' => 'assistant',
            'body' => $reading->reply,
            'questions' => $reading->questions,
        ]);

        return response()->json([
            'turn' => $announcement->id,
            'reply' => $reading->reply,
            'questions' => $reading->questions,
            'brand' => $reading->brand,
            'proposals' => [],
            'draft' => $client->status->isDraft() ? $client->slug : null,
            'name' => $client->name,
            // What the browser does next. Zero means the files said nothing
            // worth proposing from, so there is nothing to ask four more times.
            'batches' => $reading->isEmpty() ? 0 : DeliverableExtractor::batchCount(),
        ]);
    }

    /**
     * PHASE TWO: propose for one slice of the board, over the stored reading.
     *
     * No attachments, no history — the digest IS the material, and replaying a
     * conversation into four mechanical calls would quadruple their cost for
     * nothing. Still not four entries in the thread either: these are one
     * answer arriving in four pieces, and four sentences saying so would bury
     * the one a person actually reads.
     *
     * ⚠️ BUT THE CARDS ARE KEPT NOW, merged onto the message the read() phase
     * wrote. The beta review reported the first interpretation of a file being
     * lost — and it was: the cards existed only in the browser, so closing the
     * tab, following a link, or an autosave reload threw away forty-eight
     * proposals somebody was halfway through accepting. The thread still reads
     * as one turn; the tray survives.
     */
    private function propose(BrandOnboardingTurnRequest $request, Client $client): JsonResponse
    {
        $slice = $request->batchSlice();

        // Nothing read yet, so nothing to propose from. Not an error: a browser
        // can ask for a batch after the digest was cleared, and answering with
        // an empty tray is the honest version of that.
        if ($slice === [] || trim((string) $client->document_digest) === '') {
            return response()->json(['proposals' => [], 'batch' => $request->batchIndex()]);
        }

        $deliverables = $client->deliverablesOrNew();

        try {
            $result = $this->extractor->turn(
                brandName: $client->name,
                deliverables: $deliverables,
                message: '',
                formValues: $request->formValues(),
                clientId: $client->id,
                userId: $request->user()->id,
                digest: $client->document_digest,
                slice: $slice,
            );
        } catch (LlmException $e) {
            report($e);

            return response()->json(
                ['error' => AssistantFailure::message($e, forStaff: true)],
                502,
            );
        }

        $cards = $this->reconciler->reconcile(
            $deliverables,
            $result->proposals,
            $request->formValues(),
        );

        $this->keepProposals($client, $request->turnId(), $cards);

        return response()->json([
            'proposals' => $cards,
            'batch' => $request->batchIndex(),
        ]);
    }

    /**
     * Add one batch's cards to the turn that announced the reading.
     *
     * Merged rather than replaced, because the four batches arrive separately
     * and each knows only its own twelve entregables. Keyed by the entregable
     * so a re-run of the same batch updates its cards instead of doubling them.
     *
     * ⚠️ SCOPED TO THE BRAND. $turnId arrives from the browser, so it is checked
     * against this client before anything is written — otherwise a hand-posted
     * id would staple one brand's proposals onto another brand's thread.
     *
     * Silent when the row is not found: a batch answering after the thread was
     * cleared should still return its cards to the screen, which is the part
     * somebody is watching.
     *
     * @param  array<int, array<string, mixed>>  $cards
     */
    private function keepProposals(Client $client, ?int $turnId, array $cards): void
    {
        if ($turnId === null || $cards === []) {
            return;
        }

        $turn = BrandOnboardingMessage::query()
            ->where('client_id', $client->id)
            ->whereKey($turnId)
            ->first();

        if ($turn === null) {
            return;
        }

        $merged = [];

        foreach ([...($turn->proposals ?? []), ...$cards] as $card) {
            $key = $card['item'] ?? $card['field'] ?? null;

            if ($key === null) {
                $merged[] = $card;

                continue;
            }

            $merged[$key] = $card;
        }

        $turn->update(['proposals' => array_values($merged)]);
    }

    /**
     * The digest, with the new reading added under its own heading.
     *
     * Appended rather than replaced: a brand's material arrives over weeks —
     * the brandbook, then the tone guide, then a workshop's notes — and the
     * second document does not supersede the first. The heading names the files
     * and the date so a later reader (model or person) can tell which reading
     * came from where.
     *
     * ⚠️ THE SAME FILES REPLACE THEIR OWN SECTION RATHER THAN ADDING ANOTHER.
     * The beta review reported "el mismo archivo interpretado distinto": the
     * brandbook was uploaded, read, and uploaded again — and the second reading
     * came back richer. It was not a fluke of sampling. Appending had put the
     * brandbook's digest in TWICE, so the proposals on the second run were made
     * over double the material. Two readings of one document do not make a
     * brand with two brandbooks, and nothing about that was visible from the
     * screen.
     *
     * Matched on the filenames, which is what a person sees and what the
     * heading already carried. Re-reading a document that genuinely changed —
     * brandbook-v2.pdf under the same name — replaces the old reading with the
     * new one, which is the behaviour somebody uploading it again is asking for.
     *
     * @param  array<int, Attachment>  $attachments
     */
    private function appendDigest(
        Client $client,
        string $digest,
        array $attachments,
        string $visual = '',
    ): string {
        $names = $this->filenames($attachments);

        $heading = '## '.implode(', ', $names)
            .' — leído el '.now()->translatedFormat('j \d\e F \d\e Y');

        $existing = $this->digestWithout($client, $names);

        // ⚠️ INSIDE the file's own section, under a heading of its own — not a
        // second column and not a section of its own. Two reasons: re-reading
        // a file replaces its whole section (see digestWithout), so a visual
        // reading kept anywhere else would survive its own document; and it
        // then travels wherever the digest travels, with nothing new to wire.
        //
        // The subheading matters as much as the text. It is what stops a
        // description of a page being read later as a definition of the brand
        // — the distinction the prompt spends a paragraph on.
        // implode over what is actually there: a page of pure graphics has no
        // text to quote, so the digest half is legitimately empty and must not
        // leave blank lines under the heading pretending something was cut.
        $body = implode("\n\n", array_filter([
            trim($digest),
            $visual === '' ? '' : "### Cómo se ve (descripción, no definición)\n\n".$visual,
        ]));

        return trim($existing === ''
            ? $heading."\n\n".$body
            : $existing."\n\n".$heading."\n\n".$body);
    }

    /**
     * The stored digest with any earlier reading of these same files removed.
     *
     * Sections are delimited by their `## ` headings, so this walks the stored
     * text splitting on them and drops the ones whose filename list matches
     * what is being read now. A digest written before this existed has no
     * heading of its own to match and is therefore left alone — which is right:
     * we cannot tell what it came from.
     *
     * @param  array<int, string>  $names
     */
    private function digestWithout(Client $client, array $names): string
    {
        $existing = trim((string) $client->document_digest);

        if ($existing === '' || $names === []) {
            return $existing;
        }

        $wanted = $this->fileKey($names);

        // PREG_SPLIT_DELIM_CAPTURE keeps the headings, so each section can be
        // reassembled with the one it belongs to.
        $parts = preg_split('/^(## .+)$/m', $existing, flags: PREG_SPLIT_DELIM_CAPTURE) ?: [];

        $kept = [];
        $skip = false;

        foreach ($parts as $part) {
            if (str_starts_with($part, '## ')) {
                // "## a.pdf, b.pdf — leído el 3 de agosto" → the filenames.
                $listed = explode(' — leído el ', mb_substr($part, 3))[0];
                $skip = $this->fileKey(array_map('trim', explode(',', $listed))) === $wanted;

                if (! $skip) {
                    $kept[] = $part;
                }

                continue;
            }

            if (! $skip) {
                $kept[] = $part;
            }
        }

        return trim(implode('', $kept));
    }

    /**
     * A set of filenames as one comparable string, order-insensitive.
     *
     * Uploading the same two files in the other order is the same upload.
     *
     * @param  array<int, string>  $names
     */
    private function fileKey(array $names): string
    {
        $names = array_map('mb_strtolower', array_filter($names));
        sort($names);

        return implode('|', $names);
    }

    /** A typed turn: one call, the whole board, exactly as it always was. */
    private function converse(BrandOnboardingTurnRequest $request, Client $client): JsonResponse
    {
        $deliverables = $client->deliverablesOrNew();

        // Nothing to attach: a turn carrying files went to read() instead.
        $attachments = [];

        $history = BrandOnboardingMessage::query()
            ->thread($client->id)
            ->get()
            ->reverse()
            ->map(fn (BrandOnboardingMessage $m): Message => $m->toLlmMessage())
            ->values()
            ->all();

        try {
            $result = $this->extractor->turn(
                brandName: $client->name,
                deliverables: $deliverables,
                message: $this->said($request),
                history: $history,
                attachments: $attachments,
                formValues: $request->formValues(),
                clientId: $client->id,
                userId: $request->user()->id,
                // What was read out of this brand's documents, so a follow-up
                // question is answered from the brandbook rather than from its
                // filename — which is all the model used to have.
                digest: $client->document_digest,
            );
        } catch (LlmException $e) {
            // The team's own turn is kept even when the reply fails: they typed
            // it, and losing it because the provider was down is the rudest
            // possible outcome.
            $this->recordUserTurn($client, $request->user()->id, $request, $attachments);

            report($e);

            return response()->json(
                ['error' => AssistantFailure::message($e, forStaff: true)],
                502,
            );
        }

        $cards = $this->reconciler->reconcile($deliverables, $result->proposals, $request->formValues());

        $this->recordUserTurn($client, $request->user()->id, $request, $attachments);

        BrandOnboardingMessage::create([
            'client_id' => $client->id,
            'role' => 'assistant',
            'body' => $result->reply,
            'proposals' => $cards,
            'questions' => $result->questions,
        ]);

        return $this->reply($result, $cards, $client);
    }

    /* ---------------------------------------------------------------------
       Shared
       --------------------------------------------------------------------- */

    /**
     * @param  array<int, array<string, mixed>>  $cards
     */
    private function reply(ExtractionResult $result, array $cards, Client $client): JsonResponse
    {
        return response()->json([
            'reply' => $result->reply,
            'questions' => $result->questions,
            'proposals' => $cards,
            // The brand's own details, when the material stated them. Offered
            // as cards like everything else — a name read off page one is still
            // a reading, and a person still says yes to it.
            'brand' => $result->brand,
            // The new-brand screen holds this so every later turn and autosave
            // names the same row rather than forking the brand in two.
            'draft' => $client->status->isDraft() ? $client->slug : null,
            'name' => $client->name,
        ]);
    }

    /**
     * Attachments, or the 422 explaining which file could not be read.
     *
     * Attachment::make() refuses anything the model cannot open. That is a
     * problem with the upload, not with the service, so it must not read as a
     * provider failure.
     *
     * @return array<int, Attachment>|JsonResponse
     */
    private function attachmentsOrFail(BrandOnboardingTurnRequest $request): array|JsonResponse
    {
        try {
            return $request->attachments();
        } catch (LlmException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }
    }

    private function said(BrandOnboardingTurnRequest $request): string
    {
        return trim((string) $request->input('message'));
    }

    /** What goes in the transcript when somebody sends files with no message. */
    private function bodyFor(BrandOnboardingTurnRequest $request): string
    {
        $said = $this->said($request);

        return $said !== '' ? $said : 'Revisa los archivos adjuntos y propón lo que encuentres.';
    }

    /**
     * @param  array<int, Attachment>  $attachments
     * @return array<int, string>
     */
    private function filenames(array $attachments): array
    {
        return array_map(static fn (Attachment $a): string => $a->filename, $attachments);
    }

    /**
     * @param  array<int, Attachment>  $attachments
     */
    private function recordUserTurn(
        Client $client,
        int $userId,
        BrandOnboardingTurnRequest $request,
        array $attachments,
    ): void {
        // ⚠️ KEPT BEFORE THE ROW IS WRITTEN, because the row wants to point at
        // the files. Before this, a brandbook read here had to be uploaded a
        // second time to end up anywhere at all. ⚠️ It lands in the PERSON'S
        // folder, not the brand's (2026-09-17) — filing it under the brand is
        // what made a pasted screenshot look like a brand asset. Here rather than in
        // read(), because every path that records a turn — including the one
        // that runs after the provider failed — should keep what it was handed.
        $kept = $this->keep->handle(
            $request->user(),
            array_values((array) $request->file('files', [])),
        );

        BrandOnboardingMessage::create([
            'client_id' => $client->id,
            'user_id' => $userId,
            'role' => 'user',
            'body' => $this->bodyFor($request),
            // Names for the model, ids for the screen. Two questions, two
            // columns — see the attachment_ids migration.
            'attachments' => $this->filenames($attachments),
            'attachment_ids' => array_map(fn ($asset) => $asset->id, $kept) ?: null,
        ]);
    }
}
