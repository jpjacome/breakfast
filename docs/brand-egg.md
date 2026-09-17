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

## 7. The toolkit as the second tier — ⛔ BUILT 2026-09-15, REMOVED 2026-09-17

> ⚠️ **THIS WHOLE SECTION DESCRIBES A TIER THAT NO LONGER EXISTS.** Breakfast
> settled what the toolkit is on 2026-09-17: it is the final PDF they deliver,
> and the 48 entregables are extracted from it. So the toolkit has nothing the
> entregables lack, and feeding its digest as a fifth tier put an unreviewed
> second account of the same facts against the reviewed one.
>
> The one thing it did carry that they did not — *"cómo se ve"* — now lives on
> `brand_assets.visual_reading` and reaches the Egg through its inventory layer.
>
> Kept below because the reasoning about ORDERING and about ksort is still true
> of the four tiers that remain, and because the two-phase read it describes is
> still how a brandbook is turned into entregables.

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

---

## 14. The Egg assistant — co-creating the layers · planned 2026-09-17

*Step 1 of §1 of the brief: «La IA administrativa guía a Breakfast para
construir el Brand Egg mediante **preguntas**, síntesis y edición conjunta.»
Síntesis and edición are built. This is the preguntas.*

### 14.1 Why it is not just a bigger composer

`EggComposer` reads entregables and writes a paragraph. It cannot help a brand
that has no entregables — and that is now the normal case, because Breakfast
inverted the flow: **the Egg is built FIRST, and the toolkit comes after.**

So the assistant has two modes, and it decides which from the data rather than
from a setting:

| the brand has | mode | what the assistant does |
|---|---|---|
| no entregables, no layers | **cold** | asks. Builds the layer out of the answers |
| entregables written | **warm** | synthesises, and asks only about what is thin |
| layers already written | **refining** | challenges, tightens, proposes edits |

⚠️ **COLD MODE MEANS THE ASSISTANT HELPS INVENT THE BRAND**, which sits against
the rule that it must never state an attribute the entregables do not carry.
That is fine here and the prompt must say why: in cold mode **every word is a
proposal a person accepts**, and nothing reaches the database unreviewed. But
the mode has to be stated in the turn, or it will invent in warm mode too —
where the entregables exist and inventing is the one thing forbidden.

### 14.2 The conversation is the TEAM's, not a person's

`brand_egg_messages`, keyed on `client_id` — two Breakfast people building one
brand's Egg see one conversation, and either can pick it up.

⚠️ **The opposite of `assistant_messages`**, which is keyed on `user_id`
precisely so nobody can read somebody else's turns. Same shape as
`brand_onboarding_messages`, which is also a team thread about one brand.

```
brand_egg_messages
  client_id · user_id (who spoke) · role · body
  proposals json · questions json · layer (which ring this turn is about)
  timestamps
```

### 14.3 How a layer is opened

Three parts, and the shape never varies:

1. **Where we are** — which layer, said in Brandy's voice and using the egg.
2. **The checklist** — the entregables that compose it, each ticked or not, each
   marked obligatorio or opcional.
3. **One question**, about the first empty obligatorio, naming that entregable.

The person always knows how much is left and what filling it is FOR, which is
the thing a free-form chat cannot tell them.

### 14.3a Every entregable is THREE BEATS, never two

**ask → play it back as a card → tick and move on.** The middle beat is the one
that is easy to drop and the one that makes the rest trustworthy:

> **ask** — Empecemos por el **Relato de marca**. Va primero porque los otros
> cinco se apoyan en él. ¿De dónde nace esta marca? No la empresa — la marca.
>
> **play back** — Entonces, a ver si te entendí: la marca nace de una abuela que
> hacía pan para la casa, empezó a repartirlo en el barrio y terminó vendiendo
> más de lo que podía amasar. ¿Lo guardo así en **Relato de marca**?
> `[Guardar]` `[Editarlo primero]`
>
> **tick** — ✅ Relato de marca · ⬜ Brand promise · ⬜ …
> Ahora la **Brand promise**: lo que su cliente puede esperar siempre, cada vez
> que compra. ¿Qué le promete esta marca?

⚠️ **THE TICK CANNOT SHARE A MESSAGE WITH THE PLAY-BACK, and not for reasons of
manners.** `LayerProgress` reads `brand_deliverables`, and that column is only
written when somebody clicks the card. A ✅ printed beside *"esto es lo que
entendí"* would be announcing a save that has not happened — the checklist
lying about the database on its very first line.

Three rules about the middle beat, each of them a way the turn goes wrong:

- **Play back the CONTENT, not the category.** *"Eso ya es un relato: no es un
  plan de negocio, es algo que pasó"* tells the team what the word means. They
  cannot check a definition; they can check a recap. Say the brand's own story
  back in one sentence and let them correct it.
- **Name the destination.** *"Lo dejo así"* leaves what, and where? The card says
  *guardar en Relato de marca*, so what is being stored and under which of the 48
  is on screen before anybody agrees to it.
- **Then ask plainly.** A concept explained as a paradox — *"tiene que poder
  incumplirse; si no se puede fallar, no es una promesa"* — is clever and lands
  as a riddle at the exact moment the person is meant to answer something. One
  clause of explanation, then the question.

**The voice is Brandy's** — warm, direct, opinionated, the register
`ai.system_prompt` already establishes. This is somebody sitting beside you
building a brand, not a wizard with a progress bar. ⚠️ **And the egg is hers to
use, because the team is literally building one**: layer 1 is the yolk and the
rest wrap around it, which is the metaphor the enum itself carries
(`BrandEggLayer::ring()`, *"1 being the yolk"*). Saying *"arrancamos por el
centro"* is not decoration — it tells somebody where they are in a five-part
object they can see on screen.

⚠️ **But she narrates it; she does not name it.** The layer's title and its
one-line purpose are `BrandEggLayer::label()` and `description()`, in
Breakfast's own words, the same strings the ring shows on hover. Brandy writes
the sentences around them. A second, warmer set of names invented per turn gives
the app two vocabularies for the same five things, drifting apart — the argument
that keeps every label on its enum (CLAUDE.md §10).

⚠️ **THE CHECKLIST IS RENDERED BY THE SERVER, NOT WRITTEN BY THE MODEL.** The
ticks come from `sources()` read against `brand_deliverables`, exactly as the
board does. A model asked to keep a tally gets it right most of the time, and a
wrongly ticked entregable is a small lie about whether the brand's promise
exists — the one kind of error this app is built to make impossible.

⚠️ **AN ENTREGABLE FEEDS MORE THAN ONE LAYER, so a later one opens PART-TICKED
and Brandy says so rather than asking again.** `Valores` feeds layers 1, 2 and
5. `Relato` feeds 1, 3 and 4. `Brand promise` and `Manifesto` feed 1 and 5;
`Brand statement` feeds 1 and 3; `Look and feel` feeds 4 and 5. By the time
layer 3 opens, two of its four are in — and *"esos dos ya los tenemos de la
yema"* is the difference between this reading as progress and reading as a form
that repeats itself.

⚠️ **AN EMPTY OPCIONAL IS OFFERED ONCE AND SKIPPED, NEVER CHASED.** ERR-07
again: not every brand has a Manifesto or a Claim, and a layer that keeps asking
for one teaches the team the Egg is unfinished when it is finished.

⚠️ **WHICH IS WHY THE CHECKLIST HAS A THIRD STATE: ➖ *no aplica*.** With only ✅
and ⬜ a brand that legitimately has no Manifesto reads 5 de 6 forever, and a
layer that can never finish is ERR-07 wearing a checkbox. ⚠️ **It is a reading,
not a column** — `brand_deliverables` still has no status field and must not grow
one (CLAUDE.md §8 rule 2). The skip is a line in the conversation, so
`LayerProgress` derives ➖ from an optional entregable that is empty AND was
offered in a turn already in `brand_egg_messages`. Nothing to keep in sync, and
a brand that later writes a Manifesto ticks it without anyone clearing a flag.

**Order: 1 → 2 → 3 → 5** — outwards from the yolk, which is also the order the
data needs, since layer 5 reads layer 2's output. ⚠️ Layer 4 is not in that
sequence: it is a list of files, so it is picked rather than written (14.8). The
person can jump anywhere; the order is a default, not a rail.

---

### 14.3b When she pushes back — the most dangerous beat

A weak answer is not saved in silence. The team asked for an assistant with an
opinion, and this is where the opinion shows:

> «Pan de calidad» no te sirve, y te digo por qué: eso lo promete cualquier
> panadería y nadie puede reclamártelo. Una promesa vale cuando se nota si no la
> cumples.
>
> Pero en lo que me contaste hay una que sí se nota: que la masa se levanta de
> noche y el pan sale caliente a las seis.
>
> **«Pan hecho anoche, caliente a las seis.»**
>
> `[Guardar esta]` `[Editarla]` `[Guardar «pan de calidad»]`

⚠️ **THE THIRD BUTTON IS THE ONE THAT MATTERS.** Declining her opinion has to be
ONE CLICK, not a paragraph the person has to write to get past her. An assistant
whose refusal costs an argument is not an assistant, it is a gate — and this is
Breakfast's brand, not hers.

⚠️ **THIS IS WHERE THE TWO HALVES OF THE PERSONA COLLIDE**, so the rules below
are not manners. CLAUDE.md §7 already names the tension: `ai.system_prompt`
wants her confident and opinionated, and the app exists to stop a model stating
what the entregables do not carry. They coexist on one distinction, and beat 3
is where it is either held or lost.

1. **Once per entregable. Never twice.** If they hold their answer, she saves it
   and moves on. A negotiation the person cannot end is worse than no opinion.
2. **Only for a reason she can say in one sentence.** No *"podría ser más
   potente"*. If she cannot name what is wrong, it is not wrong.
3. ⚠️ **THE COUNTER-PROPOSAL IS BUILT FROM THE THREAD OR THE ENTREGABLES, NEVER
   FROM BRANDING KNOWLEDGE.** *"Caliente a las seis"* is allowed because they
   said the bakery works at night. When nothing in the conversation supports an
   alternative she names the problem and asks again — **she does not invent a
   promise in order to have something to offer.** Her opinion is hers; her
   content is theirs. This single rule is the difference between an assistant
   with a view and the failure mode this whole app was built around.
4. **Three exits, always:** accept hers, edit it, keep theirs.
5. **She never pushes back on the `relato`.** A relato is what happened. She may
   ask for more detail; *"that is not a good origin story"* is not a sentence
   she may say about somebody's history.
6. **Three named triggers, so it is not a mood:**
   - **cliché** — the answer would fit any brand in the category.
   - **contradiction** — it disagrees with something already in the thread or in
     another entregable. ⚠️ This is also §1 of the brief's *«señala al admin»*,
     reaching a person for the first time.
   - **wrong question answered** — they described what the brand DOES when asked
     what it PROMISES. The commonest of the three and the most useful catch.

⚠️ **A CONTRADICTION IS THE ONE THING RULE 1 DOES NOT CLOSE.** Cliché and wrong
question are matters of taste and one push is enough. But an answer that
disagrees with the brand's own relato, accepted and left alone, composes a layer
out of two statements that contradict each other — and the Egg is the tier
everything else is read against. So a contradiction raised once and declined
comes back **at composition time, as a note on the layer card** —
*"ojo: la promesa dice X y el relato dice Y"* — never as a second round in the
chat. Said where the person is already deciding, once, and not again.

**No carve-out for `publicos`**, though it was considered: a público of *"todo el
mundo"* deserves a push, and trigger 6's three cases already cover when to give
it one.

### 14.4 Layer 1 · Esencia, tagline y valores — the yolk

**Reads** — six entregables, four obligatorios: `relato` · `brand_promise` ·
`brand_statement` · `valores` · `manifesto` *(opc.)* · `claim` *(opc.)*

The opening, as an illustration of the shape 14.3 describes — the list is
`LayerProgress`, the rest is Brandy:

> Arrancamos por el centro del huevo, la yema: lo que la marca **es** — su
> esencia, su promesa y los valores que la sostienen. Todo lo demás se envuelve
> alrededor de esto, así que vale la pena tomárnoslo con calma. Son seis
> entregables:
>
> - ⬜ Relato de marca · *obligatorio*
> - ⬜ Brand promise · *obligatorio*
> - ⬜ Brand statement · *obligatorio*
> - ⬜ Valores de marca · *obligatorio*
> - ⬜ Manifesto · *opcional*
> - ⬜ Claim · *opcional*
>
> Empecemos por el **Relato de marca**. Va primero porque los otros cinco se
> apoyan en él. ¿De dónde nace esta marca? No la empresa — la marca. ¿Qué pasó
> para que alguien decidiera que esto tenía que existir?

From there, each entregable runs the three beats of 14.3a.

One question per entregable, each naming it:

| entregable | what she asks |
|---|---|
| **Relato** | ¿De dónde nace? ¿Qué pasó para que alguien decidiera que tenía que existir? |
| **Brand promise** | ¿Qué puede esperar su cliente siempre, cada vez? Eso es lo que promete |
| **Brand statement** | ¿Cómo se presenta en dos líneas? Qué es, para quién, y por qué importa |
| **Valores** | ¿Cuáles son las tres o cuatro cosas que no negocia, ni cuando cuestan dinero? |
| **Manifesto** *(opc.)* | ¿Hay algo que esta marca defiende y diría en voz alta? Si no, lo saltamos |
| **Claim** *(opc.)* | ¿Ya usan una frase para cerrar? Si no, propongo tres cuando tengamos el resto |

With the six in, the composed text arrives as a card: accept, edit, or ask
again. **Warm start** opens with the ticks already on and asks only about the
empties. **Never invents a value** — if only the Relato is there, the layer says
what the Relato supports and stops.

---

### 14.5 Layer 2 · Personalidad

**Reads** — two: `arquetipos` · `valores` **(already ticked from the yolk)**. So
this layer opens with a single question, and saying that out loud — *"de aquí
sale casi todo solo, falta uno"* — is most of the work.

| entregable | what she asks |
|---|---|
| **Arquetipos** | Offers two or three that fit the relato and the valores, with her reasoning — and a person picks |

⚠️ **Arquetipos is a closed vocabulary.** Writing "El Cuidador" into the
entregable on her own authority is precisely the invention the app exists to
stop. She proposes; somebody chooses.

**Composition questions**, once the entregables are in — these produce the
layer's text, not an entregable: ¿habla primero o escucha? · ¿de qué se ríe, y
qué no le haría gracia nunca? · ¿tú o usted? · ¿qué frase no diría jamás, aunque
funcionara?

**Feeds layer 5.** Composed before it, always.

---

### 14.6 Layer 3 · Beneficios de marca

**Reads** — four, of which two come ticked from the yolk: ✅ `relato` ·
✅ `brand_statement` · ⬜ `publicos` · ⬜ `insight`

| entregable | what she asks |
|---|---|
| **Públicos** | ¿A quién le cambia el día? Una persona concreta, no un segmento — qué hace un martes, qué le preocupa |
| **Insight** | ¿Qué le molesta HOY a esa persona, que esta marca resuelve? Una tensión, no una necesidad |

**Composition question:** ¿qué se lleva quien la elige que no se llevaría de
otra? — y de eso, ¿qué es práctico, qué es emocional y qué dice de quien la usa?

**Never:** claim what competitors do or do not offer. Nothing in the context
knows that, and it is the easiest sentence in this layer to invent.

---

### 14.7 Layer 5 · Brand Universe / Emotions — the outside

**Reads** — four, three ticked from the yolk: ✅ `valores` · ✅ `brand_promise` ·
✅ `manifesto` · ⬜ `look_and_feel` — **plus layer 2's text** (`dependsOn()`).

| entregable | what she asks |
|---|---|
| **Look and feel** | ¿Cómo se ve este mundo? No los colores todavía — la sensación: ¿luminoso o en penumbra? ¿limpio o cargado? |

**Composition questions:** si fuera un lugar, ¿cuál? · ¿qué se siente al estar
dentro — una emoción, no cinco? · ¿qué queda fuera de ese mundo?

⚠️ **Refuses to run before layer 2 exists**, and says so instead of composing
from the entregables alone. Already enforced by `dependsOn()` in `EggComposer`.

**Warm:** extends layer 2 rather than restating it — personality is how the
brand behaves, universe is the world that behaviour creates.

---

### 14.8 Layer 4 · Brand Assets — a checklist with two halves

**Not prose. An inventory** (`brand_egg_assets`). Its checklist is unlike the
others: eleven definitions on one side, the brand's actual files on the other,
and much of the work is Brandy naming where the two disagree.

> **Definiciones** — 4 de 11 · ✅ Look and feel · ✅ Colores · ✅ Relato ·
> ✅ Emblemas · ⬜ Identificativo principal *(oblig.)* · ⬜ Brand universe
> *(oblig.)* · ⬜ Identificativo secundario · ⬜ Tipografía · ⬜ Ilustraciones ·
> ⬜ Personaje · ⬜ Aplicaciones
>
> **Archivo** — 14 ficheros, 6 sin clasificar
>
> Dos cosas no cuadran: **Colores** está escrito y no hay ninguna paleta
> archivada, y hay un PNG que se llama *marca-horizontal* y tiene toda la pinta
> del identificativo principal. ¿Lo sumo al inventario?

| she does | the card |
|---|---|
| suggests which files belong, from `type` and `visual_reading` | **toggles an asset**, posting to `clients.egg.asset` |
| flags the unclassified | *"6 archivos sin tipo. ¿Alguno es el logo?"* — sets `brand_assets.type` |
| flags each mismatch, both ways | a definition with no file filed under it; a file no definition mentions |
| asks the eleven definition questions | ordinary entregable proposals, as on every other layer |

⚠️ **Two card types on one layer, which no other has.** Elsewhere a proposal
fills a textarea; here half of them do and half toggle a row. Build it last,
once the text layers work.

### 14.9 ⚠️ Four entregables Breakfast validates that feed no layer

§1 of the brief says Breakfast validates *esencia, promesa, **territorio** e
insight, públicos, personalidad y valores, **tono**, **pilares**, **mensajes**,
criterios visuales*.

Four of those reach nothing today, and two are **obligatorios**:

| entregable | proposed home | why |
|---|---|---|
| `tono` *(oblig.)* | **layer 2** | the layer is literally "cómo se comporta y HABLA" |
| `territorio` *(oblig.)* | **layer 5** | territory is the world the brand occupies |
| `pilares_contenido` | **layer 2** | what it talks about is part of how it speaks |
| `temas_conversacion` *(oblig.)* · `lineamientos` | **layer 2** | "mensajes" in their list |

**This is a question for Breakfast, not a decision to take here.** Their two
documents enumerate the Egg differently — the 2026-09-08 brief names five
layers, §1 names eleven things — and building against the wrong reading is
expensive. ⚠️ **Ask before wiring.**

---

### 14.10 What gets built

| | |
|---|---|
| `brand_egg_messages` | migration + model |
| `App\Services\BrandEgg\EggAssistant` | one turn in, `{reply, proposals[], questions[]}` out |
| `config('ai.egg_assistant_prompt')` | block 1, identical bytes for every brand and every layer |
| `POST …/brand-egg/asistente` | `throttle:10,1` + `ai-turn`, JSON, FormRequest with `failedValidation()` |
| `App\Services\BrandEgg\LayerProgress` | one layer's checklist: each source entregable, filled or not, required or not, and which earlier layer already filled it. The ONE class that decides a tick |
| `<x-brand-egg.checklist>` | renders it above the turn. Server-side, never the model's words (14.3) |
| `PATCH …/entregables/{item}` | the one-entregable write the play-back card accepts into. ⚠️ NOT the board's route — see 14.12 |
| the panel on the existing screen | reusing `assistant-composer.js`, so Enter and ↑-recall behave as on the other three |

⚠️ **Block 1 carries no layer name and no brand name.** Which ring, which mode
and the current state all go in the user turn — five layers each with their own
system block is five prefixes, the mistake §6 already documents for the
composer.

### 14.11 Sequencing

1. table + `EggAssistant` + prompt + route. No UI; provable by tests.
2. the panel, and proposals landing in the four text layers.
3. cold-start behaviour, tuned against a real empty brand.
4. layer 4's asset cards.

### 14.12 ✅ DECIDED — the chat writes entregables, and the Egg is composed from them

**When Breakfast answers «¿de dónde nace esta marca?» in the chat, that answer
lands in the `relato` entregable.** Confirmed 2026-09-17. The play-back card of
14.3a already says so out loud — *"¿lo guardo así en **Relato de marca**?"* —
and naming the destination is what makes it honest.

So the order never inverts: **the conversation fills tier 2, and tier 1 is
composed from tier 2, exactly as `EggComposer` already does it.** A brand can
never end up with a full Egg sitting over empty entregables, and the cold path
and the warm path converge on the same shape rather than being two systems.

⚠️ **THIS MAKES IT THE THIRD DOOR ONTO `brand_deliverables`** — the board's
form, the onboarding assistant's proposals, and now this. That is the shape
CLAUDE.md §11 warns about for the file manager (*"a folder filled from two doors
that disagree is a folder nobody trusts"*), and it is acceptable here for the
same reason it is there: **every door is a person clicking**, and none of them
writes on the model's authority. §8 rule 4 still holds — no provenance column,
because there is no state where the model authored a value alone.

⚠️ **BUT IT CANNOT POST TO THE BOARD'S ROUTE.**
`ClientProcessController::update()` takes `UpdateDeliverablesRequest` and fills
from `$request->deliverables()`, which is the WHOLE set as the board's form
submits it. A card posting one entregable through it would blank the other 47 —
a data loss with no error and no way back, triggered by accepting a suggestion.

So the card needs its own narrow write: **one entregable, named in the route,
nothing else touched.**

```
PATCH  /admin/clientes/{marca}/entregables/{item}    admin.clients.deliverable.update
```

- `{item}` is bound to `DeliverableItem`, so an unknown key is a 404 rather than
  a column name arriving from a request — the enum is the whitelist.
- `covers-client` already guards it, being under the `/admin` group with a
  `{client}` segment (CLAUDE.md §6).
- It answers JSON, because the caller is a `fetch()` (trap 13).
- ⚠️ **It writes the text it is given, not text the model produced.** The card
  carries what the person saw and accepted; the endpoint does not call the
  assistant, re-generate, or re-phrase.

**It is not only the Egg assistant's.** The same route is what the entregables
board should use the day somebody wants a single field saved without submitting
48 — so it is written as a general one-entregable write, in the Actions style of
CLAUDE.md §10, rather than as a private helper of this feature.
