<?php

declare(strict_types=1);

namespace App\Services\Ai\Brand;

use App\Enums\DeliverableItem;
use App\Models\BrandDeliverables;
use App\Services\Ai\Contracts\LlmClient;
use App\Services\Ai\Data\Attachment;
use App\Services\Ai\Data\DeliverableProposal;
use App\Services\Ai\Data\DocumentReading;
use App\Services\Ai\Data\ExtractionResult;
use App\Services\Ai\Data\LlmResponse;
use App\Services\Ai\Data\Message;
use App\Services\Ai\Exceptions\LlmException;
use App\Services\Ai\UsageRecorder;

/**
 * The onboarding assistant: reads what the Breakfast team hands it and
 * proposes content for the 48 entregables, one turn at a time.
 *
 * Message layout, and the reason for it:
 *
 *   ┌─ system: onboarding instructions ─┐  identical for every client, forever
 *   ├─ system: the 48-entregable schema ┤  identical for every client, forever
 *   ├─ …the conversation so far ────────┤  grows, extending the cached prefix
 *   └─ user: form state + this turn ────┘  volatile, always last
 *
 * The two system blocks are the expensive part — the schema alone is most of
 * the prompt — and they are byte-identical across every request the app ever
 * makes, so on a provider with prefix caching they are paid for once. That is
 * only true while nothing volatile sits above the final turn, which is why the
 * form state is stapled to the current message rather than kept in a system
 * block where it would read more naturally.
 *
 * Stored history is the RAW text of each turn, never the decorated version:
 * replaying yesterday's form state as if it were today's is how a model ends
 * up arguing that an entregable is empty when the person is looking at it
 * filled.
 *
 * ⚠️ A TURN THAT CARRIES FILES IS TWO PHASES, NOT ONE — read(), then turn()
 * once per slice of the board. Reading a brandbook and writing forty-eight
 * proposals in a single generation runs 60–150 seconds, and this host kills a
 * request at ~180s while long requests starve the worker pool badly enough that
 * the PUBLIC SITE starts answering 503. Measured 2026-08-18; the whole failure
 * is invisible, because the worker dies before Laravel's handler runs and
 * nothing reaches laravel.log.
 *
 * So the file is read ONCE into a digest kept on the brand, and the proposals
 * are asked for afterwards over that text, twelve entregables at a time. Each
 * call is short, the PDF is uploaded and paid for once instead of per slice,
 * and — the part that is not a workaround — the brand keeps what was read,
 * which it never did before.
 *
 * ⚠️ THE SLICE AND THE DIGEST GO IN BLOCK 3, never above it. Slicing the schema
 * block would give each batch a different prefix and turn one cached read into
 * four uncached ones; the digest changes per brand, which is exactly what a
 * shared prefix must not do. Blocks 1 and 2 stay byte-identical across every
 * request this app has ever made, and BrandContextCachingTest pins it.
 */
final class DeliverableExtractor
{
    /**
     * Entregables asked for per proposal call.
     *
     * Twelve is four calls over the 48. Sized from the live host: a request is
     * killed at ~180s and long ones poison the pool for everything behind them,
     * so the target is ~20s per call — six times inside the ceiling, and short
     * enough that a worker is never held long enough to matter. Fewer, larger
     * batches would fit too; they would just bring back the failure the first
     * time a brandbook was denser than usual.
     */
    public const BATCH_SIZE = 12;

    public function __construct(
        private readonly LlmClient $client,
        private readonly DeliverableSchema $schema,
        private readonly UsageRecorder $usage,
    ) {}

    /**
     * The slices of the board, in order. Each one is a proposal call.
     *
     * @return array<int, array<int, DeliverableItem>>
     */
    public static function batches(): array
    {
        return array_chunk(DeliverableItem::cases(), self::BATCH_SIZE);
    }

    public static function batchCount(): int
    {
        return count(self::batches());
    }

    /**
     * PHASE ONE: read the files, keep the reading, propose nothing.
     *
     * Bounded on purpose. The output is a digest of what the documents say —
     * long enough to answer from later, short enough to generate in seconds —
     * and no proposals, because generating those is the part that used to take
     * the request past what this host allows. What comes back is stored on the
     * brand and every later call reasons over it instead of the PDF.
     *
     * The instruction for this phase lives in the USER turn rather than in a
     * system block. Blocks 1 and 2 are the cached prefix and must stay
     * byte-identical across every request the app makes; a second system block
     * that said "this time, summarise" would give this call its own prefix and
     * pay full price for the schema on every upload.
     *
     * @param  array<int, Attachment>  $attachments
     *
     * @throws LlmException
     */
    public function read(
        string $brandName,
        string $message,
        array $attachments,
        ?string $existingDigest = null,
        ?int $clientId = null,
        ?int $userId = null,
    ): DocumentReading {
        $messages = [
            Message::system($this->instructions()),
            Message::cacheableSystem($this->schemaBlock()),
            Message::userWithAttachments(
                $this->readingTurn($brandName, $message, $existingDigest),
                $attachments,
            ),
        ];

        try {
            $response = $this->client->json($messages, role: 'content', options: [
                // Same reasoning as a proposal turn: this is reading, not
                // writing, and the default temperature invents.
                'temperature' => 0.1,
            ]);
        } catch (LlmException $e) {
            $this->usage->recordFailure(
                $e,
                clientId: $clientId,
                userId: $userId,
                operation: 'brand-onboarding-read',
                model: $this->client->modelFor('content'),
            );

            throw $e;
        }

        $this->usage->record(
            $response,
            clientId: $clientId,
            userId: $userId,
            operation: 'brand-onboarding-read',
        );

        $decoded = $response->toArray();

        return new DocumentReading(
            reply: trim((string) ($decoded['reply'] ?? '')),
            digest: trim((string) ($decoded['digest'] ?? '')),
            visual: trim((string) ($decoded['visual'] ?? '')),
            questions: $this->questions($decoded),
            brand: $this->brandDetails($decoded['brand'] ?? null),
            response: $response,
        );
    }

    /**
     * One turn: the person says something and/or attaches files, the assistant
     * answers and proposes entregable content.
     *
     * @param  array<int, Message>  $history
     * @param  array<int, Attachment>  $attachments
     * @param  array<string, string>  $formValues  Unsaved form state, key => value.
     * @param  string|null  $digest  What was read out of this brand's documents.
     * @param  array<int, DeliverableItem>|null  $slice  Null means the whole board.
     *
     * @throws LlmException
     */
    public function turn(
        string $brandName,
        BrandDeliverables $deliverables,
        string $message,
        array $history = [],
        array $attachments = [],
        array $formValues = [],
        ?int $clientId = null,
        ?int $userId = null,
        // What was read out of this brand's documents, kept because the
        // files themselves do not travel again. See read().
        ?string $digest = null,
        // Which entregables this call is responsible for. Null is the whole
        // board, which is right for a typed turn and wrong for the calls
        // that follow an upload — see batches().
        ?array $slice = null,
    ): ExtractionResult {
        $messages = [
            Message::system($this->instructions()),
            // The expensive block, and the one that never changes. Marked so
            // providers that need an explicit breakpoint (Gemini) can cache it
            // instead of re-reading the whole schema every turn.
            Message::cacheableSystem($this->schemaBlock()),
            ...$history,
            Message::userWithAttachments(
                $this->currentTurn($brandName, $deliverables, $message, $formValues, $digest, $slice),
                $attachments,
            ),
        ];

        try {
            $response = $this->client->json($messages, role: 'content', options: [
                // Extraction is a reading task, not a writing one. The default
                // 0.8 is set for brand copy and invents here.
                'temperature' => 0.1,
            ]);
        } catch (LlmException $e) {
            $this->usage->recordFailure(
                $e,
                clientId: $clientId,
                userId: $userId,
                operation: 'brand-onboarding',
                model: $this->client->modelFor('content'),
            );

            throw $e;
        }

        $this->usage->record(
            $response,
            clientId: $clientId,
            userId: $userId,
            operation: 'brand-onboarding',
        );

        return $this->parse($response);
    }

    /**
     * Decode the reply, discarding anything that is not a usable proposal.
     *
     * JSON mode guarantees the output parses, not that it has the shape asked
     * for, so every field here is treated as untrusted input.
     */
    private function parse(LlmResponse $response): ExtractionResult
    {
        $decoded = $response->toArray();

        $proposals = [];
        $seen = [];

        foreach ($decoded['proposals'] ?? [] as $raw) {
            $proposal = DeliverableProposal::fromArray($raw);

            if ($proposal === null) {
                continue;
            }

            // A model that proposes the same entregable twice in one reply has
            // contradicted itself; the last word wins, as it would in prose.
            $seen[$proposal->item->value] = $proposal;
        }

        // Re-ordered into board order so the reply is stable regardless of
        // what order the model happened to emit.
        foreach (DeliverableItem::cases() as $item) {
            if (isset($seen[$item->value])) {
                $proposals[] = $seen[$item->value];
            }
        }

        $questions = $this->questions($decoded);

        return new ExtractionResult(
            reply: trim((string) ($decoded['reply'] ?? '')),
            proposals: $proposals,
            questions: $questions,
            response: $response,
            brand: $this->brandDetails($decoded['brand'] ?? null),
        );
    }

    /**
     * The questions the assistant wants answered, as clean strings.
     *
     * Shared by both phases: a reading asks questions as readily as a proposal
     * does, and two decoders would drift.
     *
     * @param  array<string, mixed>  $decoded
     * @return array<int, string>
     */
    private function questions(array $decoded): array
    {
        return array_values(array_filter(array_map(
            static fn (mixed $q): string => trim((string) (is_string($q) ? $q : '')),
            is_array($decoded['questions'] ?? null) ? $decoded['questions'] : [],
        )));
    }

    /**
     * The brand's own details, whitelisted.
     *
     * These land on the clients table, not on brand_deliverables — a different
     * table for a different question, so they come back under their own key
     * rather than pretending to be a 49th entregable.
     *
     * The whitelist is the whole validation: anything the model invents a key
     * for is dropped, and status is deliberately NOT in it. Whether a brand is
     * activo or pausado is a commercial fact about the relationship, and no
     * document can tell you it.
     *
     * @return array<string, string>
     */
    private function brandDetails(mixed $raw): array
    {
        if (! is_array($raw)) {
            return [];
        }

        $allowed = ['name', 'industry', 'contact_name', 'contact_email'];
        $details = [];

        foreach ($allowed as $key) {
            $value = trim((string) ($raw[$key] ?? ''));

            if ($value !== '') {
                $details[$key] = mb_substr($value, 0, 190);
            }
        }

        return $details;
    }

    /* ---------------------------------------------------------------------
       Prompt blocks
       --------------------------------------------------------------------- */

    /** Block 1. Never interpolate anything into this — see the class docblock. */
    private function instructions(): string
    {
        return (string) config('ai.onboarding_prompt');
    }

    /** Block 2. Generated from DeliverableItem, so it cannot fall behind the board. */
    private function schemaBlock(): string
    {
        return "ENTREGABLES\n\n"
            ."Cada línea es: clave [obligatorio|opcional] Etiqueta — cómo debe verse el contenido.\n"
            ."La clave es lo único que puedes usar en \"entregable\".\n\n"
            .$this->schema->promptBlock()
            ."\n\nDATOS DE LA MARCA\n\n"
            ."No son entregables y no van en \"proposals\": van en \"brand\", aparte.\n"
            ."Sólo estas cuatro claves; cualquier otra se descarta.\n\n"
            ."- name: cómo se llama la marca\n"
            ."- industry: a qué se dedica, en pocas palabras\n"
            ."- contact_name: la persona de contacto\n"
            .'- contact_email: su correo';
    }

    /**
     * The volatile turn: what is already in the form, then what was just said.
     *
     * The state is here so the assistant can tell an empty entregable from a
     * filled one without being told, which is what stops it re-proposing the
     * same twenty values on every message.
     *
     * @param  array<string, string>  $formValues
     * @param  array<int, DeliverableItem>|null  $slice
     */
    private function currentTurn(
        string $brandName,
        BrandDeliverables $deliverables,
        string $message,
        array $formValues,
        ?string $digest = null,
        ?array $slice = null,
    ): string {
        $filled = [];
        $empty = [];

        foreach (DeliverableItem::cases() as $item) {
            $value = array_key_exists($item->value, $formValues)
                ? trim($formValues[$item->value])
                : $deliverables->value($item);

            if ($value === '') {
                $empty[] = $item->value.($item->isRequired() ? ' (obligatorio)' : '');

                continue;
            }

            // Truncated on purpose: the assistant needs to know an entregable
            // is taken and roughly what it says, not to re-read the whole board
            // on every turn at full price.
            $filled[] = "{$item->value}: ".mb_substr((string) preg_replace('/\s+/u', ' ', $value), 0, 200);
        }

        $state = 'MARCA: '.($brandName !== '' ? $brandName : 'todavía sin nombre')."\n\n"
            ."ENTREGABLES YA ESCRITOS — no los toques salvo que encuentres algo que los contradiga,\n"
            ."y si lo encuentras dilo en \"reply\" antes de proponer el cambio:\n"
            .($filled === [] ? '(ninguno todavía)' : implode("\n", $filled))
            ."\n\nENTREGABLES VACÍOS — estos son los que hay que llenar:\n"
            .($empty === [] ? '(ninguno: el tablero está completo)' : implode(', ', $empty));

        $said = trim($message);

        return $state
            .$this->digestBlock($digest)
            .$this->sliceBlock($slice)
            ."\n\n---\n\nMENSAJE DEL EQUIPO BREAKFAST:\n"
            .($said === '' ? '(sin texto: lee los archivos adjuntos y propón lo que encuentres)' : $said);
    }

    /**
     * PHASE ONE, block 3: read these files and tell me what they say.
     *
     * The instruction is here rather than in a system block on purpose. Blocks
     * 1 and 2 are the cached prefix — identical for every brand, every turn,
     * forever — and a system block that said "this time, summarise" would give
     * uploads their own prefix and pay full price for the 48-entregable schema
     * on every single one.
     *
     * ⚠️ IT ASKS FOR NO PROPOSALS. That is the whole point of the split: the
     * proposals are four short calls afterwards, over the digest this returns.
     * If this phase starts proposing again the request grows back to the size
     * that was being killed at ~180s.
     */
    private function readingTurn(string $brandName, string $message, ?string $existing): string
    {
        $said = trim($message);

        return 'MARCA: '.($brandName !== '' ? $brandName : 'todavía sin nombre')
            ."\n\nESTE MENSAJE ES SÓLO DE LECTURA. Lee los archivos adjuntos y"
            .' devuelve lo que dicen. NO propongas entregables todavía: de eso'
            ." se encargan los mensajes siguientes, uno por tanda del tablero.\n\n"
            ."Devuelve JSON con estas claves:\n\n"
            .'- "digest": todo lo que la marca dice de sí misma en esos'
            .' archivos, en tus palabras y en español. Es lo único que vamos a'
            .' guardar: los archivos no vuelven a viajar, así que lo que no esté'
            .' aquí se pierde. Cita textualmente lo que sea literal — nombres,'
            .' claims, hex de colores, tipografías, cifras. Ordénalo por temas'
            .' con títulos cortos. Si el documento no dice algo, no lo inventes'
            ." ni lo rellenes: lo que falta se queda fuera.\n"
            .'- "visual": cómo SE VE el material, en palabras. Los archivos no'
            .' vuelven a viajar, así que esto es lo único que va a quedar de su'
            .' aspecto. Describe composición, uso del color, densidad, estilo'
            .' fotográfico o de ilustración, aire, tono general. Sé concreto:'
            .' «páginas negras con un único bloque de texto centrado y mucho'
            .' aire» dice algo; «diseño moderno y limpio» no dice nada.'."\n"
            .'  ESTO ES UNA DESCRIPCIÓN, NO UNA DEFINICIÓN. Cuentas lo que ves'
            .' en las páginas; no decides que eso SEA la tipografía, el color o'
            .' el estilo de la marca. Eso sólo se propone si está escrito, y va'
            .' en "digest" y en "proposals" como siempre. No nombres fuentes ni'
            .' marcas gráficas por su aspecto, ni aquí ni en ningún lado.'."\n"
            .'  Si el material es sólo texto, o es un audio, devuelve "".'."\n"
            .'- "reply": una o dos frases para el equipo, diciendo qué acabas de'
            ." leer. Sin listas.\n"
            ."- \"questions\": lo que el documento deja sin responder y haría falta.\n"
            ."- \"brand\": nombre, industria y contacto, si el documento los dice.\n"
            ."- \"proposals\": déjalo vacío en este mensaje.\n"
            .$this->digestBlock($existing)
            ."\n\n---\n\nMENSAJE DEL EQUIPO BREAKFAST:\n"
            .($said === '' ? '(sin texto: lee los archivos adjuntos)' : $said);
    }

    /**
     * What was read out of this brand's documents, if anything has been.
     *
     * In the volatile turn rather than in a system block, and the reason is
     * the prompt cache: blocks 1 and 2 are byte-identical for every brand
     * forever, and a per-brand block above the question is precisely what
     * stops being true. Costs a few hundred tokens a call and keeps the cache.
     */
    private function digestBlock(?string $digest): string
    {
        $digest = trim((string) $digest);

        if ($digest === '') {
            return '';
        }

        return "\n\n---\n\nLO QUE YA LEÍMOS DE LOS DOCUMENTOS DE ESTA MARCA.\n"
            .'Es tu propia lectura, guardada. Trátala como la fuente: los'
            ." archivos originales ya no viajan en cada mensaje.\n\n"
            .$digest;
    }

    /**
     * The entregables this call is responsible for.
     *
     * Without it every slice would propose the whole board: four calls doing
     * the same work four times, and four readings of the same field to
     * reconcile. Null means the whole board, which is right for a typed turn.
     *
     * @param  array<int, DeliverableItem>|null  $slice
     */
    private function sliceBlock(?array $slice): string
    {
        if ($slice === null || $slice === []) {
            return '';
        }

        $keys = implode(', ', array_map(
            static fn (DeliverableItem $item): string => $item->value,
            $slice,
        ));

        return "\n\n---\n\nEN ESTE MENSAJE SÓLO TE TOCAN ESTOS ENTREGABLES:\n"
            .$keys
            ."\n\nNo propongas ninguno que no esté en esa lista: de los demás se"
            .' ocupa otro mensaje. Si en esta tanda no hay nada que puedas'
            .' sostener con lo leído, devuelve "proposals": [].';
    }
}
