# The Brand Egg — implementation plan

From the brief of 2026-09-08 and Breakfast's follow-up of 2026-09-10.

> Necesitamos crear en el administrador una plantilla de «Brand Egg» que Brandy
> complete automáticamente al cargar el toolkit de una marca […] Una vez
> aprobado, el Brand Egg será la memoria principal de la marca, mientras que el
> toolkit quedará como fuente de respaldo e información visual.
>
> La IA debe crear el Brand Egg de cada marca basándose en estas categorías.

---

## 1. What it actually is

**Five layers, each SYNTHESISED from a named set of entregables.**

It is not a copy of the 48 and not a second template somebody fills by hand. It
is a derived reading: the AI is asked to find the *relationship* between a
handful of entregables and write one coherent statement out of them. Hence the
metaphor — layers around a core.

This resolves the apparent contradiction in the brief. *"Complete
automáticamente al cargar el toolkit"* and *"basándose en estas categorías [de
entregables]"* are both true, in sequence:

```
toolkit (PDF)  →  48 entregables  →  Brand Egg (5 layers)
   upload          already built        the new stage
```

Stage one exists and is untouched: `BrandOnboardingController::read()` stores a
digest, then four batches propose entregables a person accepts (CLAUDE.md §7).
The Brand Egg is a **third** stage that runs over the accepted entregables.

⚠️ **The Egg is generated from the ENTREGABLES, not from the toolkit.** Going
straight from the PDF would skip the one review a person actually performs, and
put an unreviewed reading at the top of the brand's memory — the exact failure
this app exists to prevent.

---

## 2. The five layers and what feeds them

| # | Layer | Source entregables |
|---|---|---|
| 1 | **Essence + Tagline + Valores** | Relato de marca · Brand promise · Brand statement · Manifesto · Valores de marca · Claim |
| 2 | **Personalidad** | Arquetipos de marca · Valores de marca |
| 3 | **Beneficios de marca** | Brand statement · Relato de marca · Insight principal de marca · Públicos |
| 4 | **Brand Assets / Icons** | *(see below)* · Look and feel · Relato de marca |
| 5 | **Brand Universe / Emotions** | *Personalidad (layer 2)* · Valores de marca · Manifesto · Brand promise · Look and feel |

Ten of the twelve named inputs map onto entregables exactly. Three findings from
checking the other two against `App\Enums\DeliverableItem`:

### ⚠️ Finding 1 — the layers are a graph, not a list

**Layer 5 takes "Personalidad" as an input, which is layer 2's output.** So
generation has an order: 2 before 5. Everything else is independent and can run
in any order, or in parallel.

If Breakfast meant "the same sources layer 2 uses" rather than "layer 2's
result", the dependency disappears and layer 5 reads Arquetipos + Valores
directly. **Worth confirming** — but build it as a dependency, because that is
the reading that produces a coherent Egg rather than two layers restating the
same source.

### ⚠️ Finding 2 — layer 4 is coupled to the image work

**"Brand Assets" is not an entregable.** It is `PortalSection::BrandAssets`
(`brand-assets`, labelled "Archivos") — the brand's **files**. So layer 4 is a
*visual* synthesis over actual images, not text.

That makes layer 4 depend on point 2 of the same brief — Brandy understanding
the toolkit and Look & Feel images. **Layer 4 cannot be built before that
lands**, and it is the only layer with an outside dependency. The other four are
pure text and can ship first.

(If Breakfast meant the graphic *entregables* instead — Emblemas de marca, Brand
universe (gráfico), the two identificativos — then layer 4 is text like the rest
and the coupling vanishes. This is the second thing worth confirming.)

### ⚠️ Finding 3 — two inputs are optional entregables

`Manifesto` and `Claim` are among the 29 **opcionales**. Layers 1 and 5 will
routinely be asked to synthesise from partly-empty inputs.

Rules, and they matter more than the happy path:

- A layer built on thin inputs **says what it is missing rather than inventing
  it.** This is the whole reason the app exists (CLAUDE.md §8 rule 3).
- ⚠️ It must **not** phrase that as Breakfast's unfinished homework. That was
  ERR-07 of the beta review, and the approved sentence already exists:
  *"Este aspecto no forma parte de las definiciones aprobadas de la marca. Para
  mantenerme fiel a la estrategia, no voy a asumir información que no haya sido
  establecida."*
- A layer whose sources are **all** empty is not generated at all. An empty
  layer is honest; a layer of hedging is noise.

### Tagline

There is no `Tagline` entregable. **`Claim` is the match** and this plan assumes
it. It is the third thing to confirm, and the cheapest to be wrong about.

---

## 3. The artwork — `public/img/brand-egg.svg`

Breakfast supplied the Egg as an SVG **already cut into five Corel layers**, and
they line up with the five conceptual layers exactly, innermost first:

| `<g>` | ring | layer |
|---|---|---|
| `Layer_x0020_1` | the **yolk** (`#FBC01B` / `#E7A608`) | 1 · Essence + Tagline + Valores |
| `Layer_x0020_2` | first white ring | 2 · Personalidad |
| `Layer_x0020_3` | second | 3 · Beneficios de marca |
| `Layer_x0020_4` | third | 4 · Brand Assets / Icons |
| `Layer_x0020_5` | outer edge | 5 · Brand Universe / Emotions |

The core being the yolk is not a coincidence worth ignoring — **the essence is
literally the centre**, and the admin screen should BE that drawing, not a
list of five textareas beside a decorative picture.

Each `<g>` renders correctly on its own (verified: rendered standalone, all
five). So a layer is a real hover target, a real click target, and its **state
can be its colour** — a layer with no text yet is the outline alone.

Four things to respect when it goes into a Blade component:

- ⚠️ **Inline it, never `<img src>`.** An `<img>` is one opaque box: no per-layer
  hover, no state, no click. 24KB inline in `x-brand-egg` is the price of the
  whole interaction.
- ⚠️ **Rename the ids to the enum's values.** `Layer_x0020_1` and
  `_2004197109760` are Corel's, and they say nothing. The `<g>` for layer 1
  should be `id="brand-egg-esencia"` — `BrandEggLayer::Esencia->value` — for the
  same reason `DeliverableItem::Relato->value` **is** the column name: one
  vocabulary, no mapping table (CLAUDE.md §8). Do the rename once, in the file.
- ⚠️ **The rings are `evenodd` compound paths painted inner → outer.** Each one
  is a closed ring, not a disc, which is what lets the five stack without
  hiding each other. **Giving a layer a solid fill would cover every layer drawn
  before it** — so "this layer is filled in" must be said by changing that
  ring's own colour, never by painting a shape over it.
- ⚠️ **Four hardcoded colours, and none is a token.** `#FBC01B`, `#E7A608`,
  `#F9EFD7`, `#020201` — near `--bkf-yellow` (`#ECBB12`), `--bkf-yellow-100`
  (`#FCF4DA`) and `--bkf-black`, but not equal to any of them. **In dark mode
  the `#020201` outline sits on a `#000000` page and the drawing disappears.**
  Two ways out and they need a decision:
  1. Treat it as artwork, like the logo — keep the exact colours, and give the
     component its own light plane to sit on in both themes.
  2. Map the four fills to `currentColor` plus the palette, so the Egg is a UI
     element that themes with everything else.

  §9 of CLAUDE.md says ask before adding a colour, so this is the ask. **(2) is
  the recommendation**, because the whole point of the screen below is that the drawing
  *responds* — and something that changes state should be theme-aware.

### The screen it makes possible

`/admin/clientes/{marca}/brand-egg` — the egg at full size, five rings, and:

- **Hovering a ring** names its layer and lists the entregables it reads.
- **Clicking a ring** opens that layer's text to read, edit and accept.
- **A ring's colour is its state**: outline only = not generated, filled = has
  text, and the whole egg carries the approval state from §5.
- **Regenerating is per ring**, not per egg. A brand whose Relato was rewritten
  needs layers 1 and 3 again, not five calls.

⚠️ **The client sees the egg too, read-only, and only once approved.** An
unapproved Egg stays on the admin side: it is the one artefact in the app whose
whole claim is that a person signed it off, and showing a draft of it would
undo that. This is the one place approval *does* gate — the prompt rules in §8
are the place it does not.


---

## 4. Storage

The layer set is **closed** — five, defined by Breakfast — which is the same
condition that made `DeliverableItem` an enum and `brand_deliverables` 48 real
columns rather than JSON (CLAUDE.md §8). Follow that precedent exactly.

```php
App\Enums\BrandEggLayer      // 5 cases
App\Models\BrandEgg          // hasOne on Client
```

```php
Schema::create('brand_eggs', function (Blueprint $table) {
    $table->id();
    $table->foreignId('client_id')->unique()->constrained()->cascadeOnDelete();

    foreach (BrandEggLayer::columns() as $column) {
        $table->text($column)->nullable();
    }

    $table->timestamp('generated_at')->nullable();
    $table->timestamp('approved_at')->nullable();
    $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
    $table->timestamps();
});
```

**The enum carries its own sources**, and its own presentation, like every enum
here. One vocabulary, no mapping table — the same argument as
`DeliverableItem::Relato->value` being the column name:

```php
enum BrandEggLayer: string
{
    case Esencia      = 'esencia';        // 1 · the yolk
    case Personalidad = 'personalidad';   // 2
    case Beneficios   = 'beneficios';     // 3
    case Assets       = 'assets';         // 4
    case Universo     = 'universo';       // 5

    /** @return array<int, DeliverableItem> */
    public function sources(): array;

    /** Layer 5 reads layer 2's output. Everything else returns null. */
    public function dependsOn(): ?self;

    public function label(): string;       // "Esencia, tagline y valores"
    public function description(): string; // what the layer is for, shown on hover
    public function ring(): int;           // 1-5, the <g> in the SVG
}
```

The case **value is the column name** on `brand_eggs`, the **id of its `<g>`** in
the SVG (`brand-egg-esencia`), and the **route segment** of its own update. Three
places, one word, nothing to keep in step.

⚠️ Nothing outside `BrandEggLayer` decides what feeds a layer. If a screen, a
prompt builder and a regeneration route each carry their own list they will
drift, and the Egg will be built from one set of entregables and explained by
another.

### The model

```php
App\Models\BrandEgg
    public function client(): BelongsTo
    public function approver(): BelongsTo      // approved_by, nullOnDelete
    public function text(BrandEggLayer $layer): ?string
    public function isEmpty(): bool            // no layer has text
    public function toMarkdown(): string       // what block 2 receives
```

`Client::brandEgg(): HasOne` and `Client::brandEggOrNew()`, mirroring
`deliverablesOrNew()` — a brand with no row is the normal case, not an error, and
every reader should get an object rather than a null check.

⚠️ **`BrandContextRepository::versionFor()` reads `deliverables.updated_at`
alone.** That string is printed into block 2 as *"Versión del contexto"*, so once
the Egg is in the block the version has to be the later of the two timestamps —
otherwise an Egg edit changes the prompt while the line claims the context did
not move.

---

## 5. Approval and staleness — no status column

### The rule this must not break

CLAUDE.md §8 rule 2: *"No status column. Filled is done, empty is pending."*

That rule governs a **single entregable**. Whether Breakfast has signed off the
Egg is asked once per brand, not per field, and cannot contradict content the
way a per-field flag can. It is not the thing rule 2 forbids — but say so in the
migration, because it looks like a violation at a glance.

### Three states, all DERIVED

`brand_deliverables` already carries `timestamps()`, so both comparisons are
free:

| state | condition |
|---|---|
| **Sin generar** | `generated_at` is null |
| **Sin aprobar** | generated, `approved_at` is null |
| **Aprobado** | `approved_at` set and `deliverables.updated_at <= approved_at` |
| **Desactualizado** | `approved_at` set and `deliverables.updated_at > approved_at` |

⚠️ **Do not store "desactualizado".** It is a flag somebody must remember to
flip when they edit an entregable, so it will be wrong. Deriving it at read time
is also what CLAUDE.md §3 asks for.

`Client::brandEggState(): BrandEggState`, and the enum carries its own
`label()` and `badgeClass()` like every other enum here.

### Approving

`POST /admin/clientes/{marca}/brand-egg/aprobar`, behind `breakfast` +
`covers-client`, writing only the two columns, answering with `back()`.

No "unapprove". Approving again re-stamps, which is what somebody means when
they approve a brand they have just edited.

---

## 6. Generation

One service, one job:

```php
App\Services\BrandEgg\EggComposer    // the only place that builds a layer
```

For each layer: take `BrandEggLayer::sources()`, read those entregables off
`BrandDeliverables`, and ask the model for **one** synthesis. Five calls, not
one — a single call producing five layers is the 60–150s request that CLAUDE.md
§3 says takes the public site down, and it is the same reason the brandbook read
was split into batches.

- Layer 2 runs before layer 5 and its output is an input to it.
- `temperature` low, like `AdminAssistant`: this is synthesis of reviewed
  material, not copywriting.
- Every call goes through `UsageRecorder` like any other.
- ⚠️ The route needs **both** gates — `throttle` and `ai-turn`. Five chained
  calls behind one click is exactly the concurrency that CLAUDE.md trap 5
  describes.

**Output is a proposal, never a write.** The composer returns text a person
accepts, the same shape as `ProposalReconciler`'s cards. There is no path where
the model's synthesis reaches the database on its own — CLAUDE.md §8 rule 4.

### The message shape, and the trap inside it

```
block 1   Message::cacheableSystem($this->instructions())   ← the SAME bytes
                                                              for every layer,
                                                              every brand
block 2   the user turn: which layer, and its source entregables verbatim
```

⚠️ **The layer's name and its sources go in the USER TURN, never in the system
block.** Five layers each with their own system block is five separate prefixes
and five paid reads of the same instructions — exactly the mistake the two-phase
brandbook read exists to avoid (CLAUDE.md §7). One instruction block, marked
once with `cacheableSystem()`, and the five calls share it.

`EggComposer::instructions()` states four things and nothing else:

1. What a Brand Egg layer is: **one coherent paragraph**, not a summary and not
   a list — the relationship between the sources, in the brand's own register.
2. Everything it says must be **traceable to the text it was given**. No
   attribute that is not in the sources.
3. A source that is empty is **named as undefined**, in the approved ERR-07
   sentence, never as pending work.
4. A **contradiction between two sources is reported, not resolved** — the brief
   is explicit about this and it is the same rule the assistant follows in §8.

### Composing one layer, or all five

`POST /admin/clientes/{marca}/brand-egg/componer` takes an optional `layer`. With
one, it runs that layer alone; with none, it runs all five in dependency order
(2 before 5) **inside one request**.

⚠️ Five sequential calls at ~20s each is ~100s, and CLAUDE.md §3 kills the
request at ~182s. That is inside the budget but not comfortably, so:

- `AI_TIMEOUT=90` already bounds a single call; the composer must also stop
  early rather than start a sixth-minute call it cannot finish.
- **Each layer is saved as it lands**, not all five at the end. A run that dies
  on layer 4 leaves layers 1, 2 and 3 written and the screen showing which are
  still empty — rather than 100 seconds spent and nothing kept.
- The per-layer button is the normal path. "Componer todo" is for a brand's
  first Egg.

---

## 7. The toolkit as the second tier — ✅ BUILT 2026-09-15

**Done, and verified against the real provider.** The four tiers are numbered
(`1. Entregables`, `2. La marca`, `3. Proceso`, `4. Toolkit (respaldo…)`) because
`BrandContext::make()` ksorts titles — the numbers hold the hierarchy instead of
the alphabet. `versionFor()` now takes the later of the deliverables' and the
client's timestamp, so reading a brandbook moves the version line with the block
it describes.

⚠️ **When the Egg lands it takes 1 and the rest shift.** One cache miss per
brand, once, and the numbering is what makes that a two-line change rather than
a silent inversion.

Asked live, with a toolkit that contradicted an entregable:

| question | answer |
|---|---|
| claim (toolkit only) | gave it, *and* said it is not in the entregables yet |
| colour (they disagree) | gave the **entregable's**, not the toolkit's |
| how does it look | answered from the visual digest, and volunteered the contradiction |

### ⚠️ The finding that reframes this point

**Brandy has never seen the toolkit.** `clients.document_digest` is written by
`BrandOnboardingController::read()` and read by *nothing else* —
`BrandContextRepository::for()` assembles three blocks (entregables, ficha,
proceso) and the digest is not among them.

The brief's worry that the toolkit is acting as *"base principal de memoria"* is
the inverse of what happens. **Nothing needs demoting; a second tier needs
adding.**

### The change

`BrandContextRepository::for()` gains the Egg **above** the entregables and the
toolkit **below** them:

```
1. Brand Egg (aprobado)     ← the primary memory, when it exists
2. Entregables              ← the reviewed detail
3. Ficha + proceso
4. Toolkit (respaldo)       ← background only
```

Four constraints:

- **All of it is block 2, not block 3.** Every piece is stable per brand, so it
  belongs in the cacheable prefix. (Contrast attachments in CLAUDE.md §7: those
  are per-turn and must stay in block 3.)
- **⚠️ The document TITLE decides the order, not the insertion.**
  `BrandContext::make()` runs `ksort($documents)` so prompt bytes never depend
  on map ordering. Today's keys sort **E**ntregables → **L**a marca →
  **P**roceso, and a "**T**oolkit…" key lands last by luck of its initial. **A
  Brand Egg key must sort ABOVE "Entregables…" or the hierarchy silently
  inverts** — prefix the keys with an ordinal rather than trusting the alphabet,
  and pin it with the test in §12.
- **It rides along with real content.** Keep the digest inside the existing
  `if ($documents !== [])` guard. Otherwise a brand with an uploaded toolkit and
  not one entregable written makes `hasUsableContext()` true, and the assistant
  is offered on the strength of a document nobody reviewed.
- **Block 2 gets bigger and there is a cap.**
  `config('ai.context.max_characters')` is 400,000 and `BrandContext::make()`
  throws `BrandContextTooLarge` past it. That guardrail is right — it fails
  loudly rather than truncating a brand definition. If a brand trips it, trim
  the digest rather than raise the cap.

---

## 8. The prompt rules

In `ai.system_prompt` (block 1, Brandy's):

- The **Brand Egg** is what the brand is, in its own words. Approved.
- The **entregables** are the detail beneath it.
- The **toolkit** is background: detail the entregables do not carry, never a
  contradiction of them.
- ⚠️ **On a contradiction, name it and stop.** The brief is explicit: *"Si
  detecta contradicciones, las señala al admin y no decide por su cuenta."*

State the approval status as a line in the brand block, so the model knows how
far to lean:

```
Brand Egg: aprobado el 3 de septiembre de 2026.
Brand Egg: aprobado el 3 de septiembre, con entregables editados después.
Brand Egg: todavía sin aprobar.
```

⚠️ **An unapproved Egg is still her memory.** The brief says the Egg becomes
primary *"una vez aprobado"*, which reads as gating. Do not implement it that
way: every brand today is unapproved, and gating would leave all of them with an
assistant that knows nothing. Approval changes what the prompt **says about**
the content, not whether it is there.

⚠️ Editing block 1 costs one cache miss per brand, once. That is the entire
cost and not a reason to avoid it.

---

## 9. Routes, controller and requests

All four under the existing `/admin` group, so `breakfast` and `covers-client`
already guard them — `covers-client` fires the moment a route carries
`{client}`, which is why every brand-scoped route in this app is safe the day it
is written (CLAUDE.md §6).

```php
Route::get('clientes/{client}/brand-egg', [ClientBrandEggController::class, 'edit'])
    ->name('clients.egg.edit');

Route::put('clientes/{client}/brand-egg/{layer}', [ClientBrandEggController::class, 'update'])
    ->name('clients.egg.update');          // a person accepting or editing one layer

Route::post('clientes/{client}/brand-egg/componer', [ClientBrandEggController::class, 'compose'])
    ->middleware(['throttle:10,1', 'ai-turn'])
    ->name('clients.egg.compose');

Route::post('clientes/{client}/brand-egg/aprobar', [ClientBrandEggController::class, 'approve'])
    ->name('clients.egg.approve');
```

`{layer}` binds straight to `BrandEggLayer` — a bad value is a 404 before the
controller runs, which is the same fail-closed shape as the rest of the app and
means no `match` with a default branch anywhere.

⚠️ **`compose` is called from JavaScript, so it needs a FormRequest with
`failedValidation()` overridden.** `bootstrap/app.php` renders JSON only for
`api/*`; a failed `validate()` on an `admin/` route answers with a **redirect**,
which `fetch()` reads as a silent failure (CLAUDE.md trap 13). Copy
`AdminAssistantRequest`.

⚠️ **`compose` carries both gates.** `throttle:10,1` because every call is paid,
and `ai-turn` because one click can hold a PHP worker for a hundred seconds and
the pool is shared with the public site (CLAUDE.md §3, trap 5). A rate limit
counts requests per minute; it cannot bound how many run at once.

`update`, `approve` and `compose` all answer with `back()`, like every other
admin write here, so a click returns to the screen it came from.

### The client's side

```php
// inside the portal group, alongside Estrategia
Route::get('estrategia/brand-egg', [PortalBrandEggController::class, 'show'])
    ->middleware('section:estrategia')
    ->name('portal.egg');
```

Read-only, gated on **read** of Estrategia, and it 404s — never 403 — when the
Egg is unapproved, for the reason in §3: a member should not learn there is a
draft of their brand's essence they are not being shown.

---

## 10. Front end

Three files, and each of them belongs to a rule in CLAUDE.md §9.

| file | |
|---|---|
| `resources/views/components/brand-egg.blade.php` | the SVG inlined, one `<g>` per layer, `id` and `data-layer` from the enum |
| `resources/css/brand-egg.css` | **a component stylesheet**, not a page one |
| `resources/js/brand-egg.js` | hover, select, and the compose calls |

⚠️ **`brand-egg.css` is rendered inside BOTH shells** — `<x-layouts.app>` on
`/admin` and `<x-layouts.portal>` on the client side — so it is the third file
in the same category as `permissions.css` and `auth.css`, and it carries their
constraint: **it may only use tokens both shells define.** That is exactly how
`permissions.css` shipped broken once, on `--box-edge`, which only the portal
had. Name it in the `:css` list of each page that renders it; never fold it into
either shell's own stylesheet.

Add all three to `vite.config.js` in the same pass — a stylesheet that is not an
entry is a page with no styles, and it fails silently in production while
looking fine under `npm run dev`.

**The component takes the egg and a mode:**

```blade
<x-brand-egg :egg="$egg" :state="$state" editable />
```

`editable` is what separates the admin's egg from the client's. Not two
components — the drawing, the rings and the state colours are identical, and a
second copy would drift the moment a ring changes shape.

**The interaction, in the smallest form that works:**

- Each `<g>` gets `tabindex="0"` and `role="button"` — a ring is a control, and
  a control that only answers a mouse is not a control.
- Hover **and focus** show the layer's `label()`, `description()` and the
  entregables it reads. Same treatment for both, so the keyboard path is not an
  afterthought.
- The selected ring's text opens beside the egg, not in a modal: the point of
  the drawing is seeing where you are in the whole while you edit one part.
- ⚠️ **State is a class on the `<g>`, never a fill painted over it.** The rings
  are `evenodd` compound paths drawn inner → outer (§3), so anything laid on top
  of a ring hides the ones inside it.

---

## 11. Build order

| | | depends on |
|---|---|---|
| 1 | ~~`BrandEggLayer` enum, `BrandEggState` enum, the migration, `BrandEgg` model, `Client::brandEggOrNew()`~~ **✅ 2026-09-15** | — |
| 2 | ~~The toolkit tier in `BrandContextRepository`~~ **✅ 2026-09-15** | — |
| 3 | ~~`EggComposer` + the four text layers (1, 2, 3, 5)~~ **✅ 2026-09-15** | 1 |
| 4 | ~~`x-brand-egg` + `brand-egg.css` + `brand-egg.js`, ids renamed, colours decided (§3)~~ **✅ 2026-09-11/15** | — |
| 5 | ~~`ClientBrandEggController` + the four routes + the compose FormRequest~~ **✅ 2026-09-15** | 1, 3, 4 |
| 6 | ~~Approval, and `versionFor()` taking the later of the timestamps~~ **✅ 2026-09-15** | 1, 5 |
| 7 | ~~Egg into the context, above the entregables~~ **✅ 2026-09-15** | 1, 2, 6 |
| 8 | ~~Prompt rules: hierarchy, contradictions, approval line~~ **✅ 2026-09-15** | 7 |
| 9 | ~~`PortalBrandEggController` — the client's read-only egg~~ **✅ 2026-09-15** | 4, 6 |
| 10 | **Layer 4** — Brand Assets / Icons. ⬜ **THE ONLY ONE LEFT** | brief point 2 (images) |

### ⚠️ What was decided differently from this plan, and why

Three places where the build diverged. Each is a contradiction inside the plan
itself rather than a change of mind, so they are recorded here rather than
quietly resolved in the code:

1. **"Output is a proposal, never a write" (§6) versus "each layer is saved as
   it lands" (§6) and "sin aprobar" being a state (§5).** Resolved as: the rule
   is about the ENTREGABLES. A composition never touches `brand_deliverables`,
   and a test pins the row byte-identical across a run. The composed layer
   itself IS written to `brand_eggs` — it has to be, or "sin aprobar" could not
   exist — and the human review it waits for is APPROVAL, which is what decides
   whether the brand ever sees it.
2. **`compose` answering JSON while `update` and `approve` answer `back()`
   (§9 asked for both).** Composing is an AI call of 20–100s driven by `fetch()`
   so the screen can say which ring it is on, and so a 429 from `ai-turn` reads
   differently from a provider outage. Accepting a layer and approving are
   ordinary admin writes and return you to the screen you clicked from.
3. **The approval line is inside the Egg's own block, not in the ficha (§8).**
   Same argument `toolkitBlock()` already makes: "above" is an ordering a model
   loses track of in a long prompt, and the ficha is two tiers away from the
   thing it would be describing.

One thing can still start immediately and answers to nothing else:

- ~~**Step 2**~~ — **built.** Brandy now sees the toolkit as a fourth, lower
  tier; see §7.
- **Step 4** is pure front end. It needs the colour decision in §3 and nothing
  more; the component can be built against a hand-written `BrandEgg` long before
  `EggComposer` exists.

**Step 10 is the only part gated on work outside this plan, and it is now the
only one open.** The other nine shipped on 2026-09-15 as a four-layer Egg whose
fourth ring composes from `Look and feel` + `Relato` alone and draws as an
outline when it has nothing — which is exactly what the state colours already
said, so nothing special was needed to express "not yet".

### Files this touches

```
app/Enums/BrandEggLayer.php                              new
app/Enums/BrandEggState.php                              new
app/Models/BrandEgg.php                                  new
app/Models/Client.php                                    + brandEgg, brandEggOrNew, brandEggState
app/Services/BrandEgg/EggComposer.php                    new
app/Http/Controllers/Admin/ClientBrandEggController.php  new
app/Http/Controllers/Portal/BrandEggController.php       new
app/Http/Requests/ComposeBrandEggRequest.php             new  (failedValidation override)
app/Services/Ai/BrandContextRepository.php               + egg block, + toolkit block, versionFor
config/ai.php                                            + hierarchy/contradiction rules, + composer prompt
database/migrations/…_create_brand_eggs_table.php        new
routes/web.php                                           + 5 routes
resources/views/components/brand-egg.blade.php           new
resources/views/admin/clients/brand-egg.blade.php        new
resources/views/portal/estrategia.blade.php              + the read-only egg
resources/css/brand-egg.css                              new  (component, BOTH shells)
resources/js/brand-egg.js                                new
vite.config.js                                           + 2 entries
public/img/brand-egg.svg                                 ids renamed, colours decided
tests/Feature/BrandEggTest.php                           new
docs/entregables.md                                      + the Egg as a third stage
CLAUDE.md                                                §7 and §8 — the Egg outranks the entregables
```

### Deployment

FTP, as always (CLAUDE.md §3). **One migration** — `brand_eggs` — run from the
cPanel terminal, plus `npm run build` and the new `public/build`. **No new env
keys**, so `portal/tests/.env` is not touched this time and the two-env trap does
not apply.

⚠️ Nothing here needs the scheduler. Composing is a click, approval is a click,
and every state in §5 is derived at read time — so a missing cron cannot leave
an Egg in a wrong state (CLAUDE.md §3).

---

## 12. Tests — `tests/Feature/BrandEggTest.php`

- each layer reads **exactly** the entregables its enum names, and no others
- layer 5 receives layer 2's output — and fails loudly rather than silently
  composing without it
- a layer with all sources empty is not generated; a layer with *some* sources
  empty says what is missing using the approved sentence, and never phrases it
  as pending work
- the four states, including that editing an entregable after approval flips
  **Aprobado → Desactualizado** with no column written
- approving twice re-stamps rather than erroring
- **ordering**: in `toPrompt()` the Egg appears above the entregables and the
  toolkit below them — the `ksort` trap in §7
- `BrandContextCachingTest` still passes: blocks 1–2 byte-stable across two
  requests for one brand, and nothing new reaches block 3
- a brand with a toolkit and no entregables still reports `hasUsableContext()`
  false
- composing is a **proposal**: `brand_deliverables` is byte-identical after a
  generation run
- an `equipo` user not on the brand gets **404** on both the compose and the
  approve routes
- a client member **without** Estrategia gets 404 on the portal egg; one **with**
  it gets 404 while the Egg is unapproved, and 200 once it is
- **the composer's prefix is byte-identical across all five layers** — the
  `cacheableSystem()` block does not carry the layer name (§6)
- a compose run that fails on layer 4 has still written layers 1, 2 and 3
- `ComposeBrandEggRequest` answers a bad payload with **JSON, not a redirect**
  (CLAUDE.md trap 13)
- a `{layer}` segment that is not a `BrandEggLayer` value 404s before the
  controller runs
- **the enum is the only source list**: every layer's `sources()` returns real
  `DeliverableItem` cases, and the count matches §2

---

## 13. Still needed from Breakfast

Three, all cheap to answer and all cheaper to ask than to guess:

1. **Layer 5's "Personalidad"** — layer 2's output, or the same sources layer 2
   reads?
2. **Layer 4's "Brand Assets"** — the brand's files (which couples it to the
   image work), or the graphic entregables (Emblemas, Brand universe, the
   identificativos)?
3. **"Tagline"** — `Claim`, as assumed here?
