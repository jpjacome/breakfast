# Implementation log — the 15-item cycle

**A running record of what was built, why, what it broke, and how it was
proved.** Written as the work happens, one section per item, newest work
appended. It is the file to read before a final review and the file to read in
six months when somebody asks why something is the way it is.

### What this is not

| file | answers |
|---|---|
| `CLAUDE.md` | **the rules.** Invariants and traps. When it disagrees with anything, it wins |
| `docs/arquitectura.md` | **the map.** What exists *right now* — routes, tables, files |
| `docs/multimarca.md`, `docs/brand-egg.md` | **the plans.** One feature each, in depth |
| **this file** | **the history.** What changed, in what order, and what it cost |

The map says the app has a `brand_user` pivot. This says *why* it appeared, what
it replaced, what nearly went wrong, and what is still owed.

---

## Status

The list of 15, as agreed. ✅ done · 🟡 partial · ⬜ not started.

| # | item | state |
|---|---|---|
| 1 | Brand Egg | 🟡 **steps 1–9 built 2026-09-15.** Only §11 step 10 is open — layer 4's images, gated on brief point 2. See §1 below |
| 2 | Multi-marca y permisos | ✅ 2026-09-14 · **step 10 closed 2026-09-15** |
| 3 | Leer las imágenes del toolkit sin segunda carga | ✅ 2026-09-14/15 |
| 4 | Mostrar imágenes en el chat, ampliar y reproducir video | ✅ 2026-09-15 |
| 5 | Historial de chats y conversación completa | ⬜ **merged with #6** — assessed, see below |
| 6 | Rediseño del Dashboard | ⬜ **next, and NOT blocked** — the two reference screenshots are pages 4–5 of `docs/Brief for Brandy correcciones.pdf`, already in the repo |
| 7 | Editar una pregunta enviada | ✅ 2026-09-15 — **solved as RECALL, not as editing in place.** The cancel half is not blocked but CLOSED: streaming is impossible on this host, measured 2026-09-16. See §7 and §9 |
| 8 | Waffle giratorio | ⬜ |
| 9 | Prueba de uso simultáneo e informe de hosting | 🟡 **hosting half MEASURED 2026-09-16 — EP limit is 30.** See §9. The session half (3+ on one account, 3+ accounts) is still open |
| 10 | Regla de seguridad: contraseñas, tokens, instrucciones | ✅ 2026-09-16 — **the exposure was the instructions, not credentials.** See §10 |
| 11 | La notificación abre la reunión correcta | ⬜ |
| 12 | Checklist agrupado por categorías | ⬜ |
| 13 | Textos aprobados y estado «proyecto cerrado» | ⬜ |
| 14 | Campos propios por marca | ⬜ |
| 15 | Notas | ⬜ |

**Suite:** 497 → **596 passing** across this cycle. `pint` clean throughout.

---

## What each of the 15 actually asks for

⚠️ **The list above is titles. This is the brief.** Source:
`Breakfast/docs/Brief for Brandy correcciones.pdf` — "BRIEF FOR BRANDY",
five sections plus the §4 corrections table. Items 7–15 came from that table
and from §5's *"Después:"* line, which is why their titles alone say so
little. **Read this before picking up any unstarted item.** Anything below
quoting the brief is Breakfast's requirement, not our interpretation.

| # | what the brief actually asks | brief § |
|---|---|---|
| 1 | **Brand Egg as the primary source.** Co-created from admin by the AI through questions and joint editing; Breakfast reviews and approves esencia, promesa, territorio e insight, públicos, personalidad y valores, tono, pilares, mensajes, criterios visuales. The toolkit loads *after* and never silently modifies an approved Egg. Brandy answers **Egg first, toolkit second**, and reports contradictions to the admin rather than deciding. Records state and date of last approval. Empty optional fields are **not** pendings nor Breakfast failures | §1 |
| 2 | **Multi-marca y permisos** | §5 *Después* |
| 3 | **Read toolkit images without a second upload.** Analyse colour, lighting, composition, style, characters, spaces and feeling; propose coherent new visual ideas, **without generating images** | §2 |
| 4 | **Show images in the chat and in "Tu marca"** — never just a URL. One upload serves admin, portal and chat; enlarge images, play video; always respect interno/compartido. If a format cannot be opened, describe it verbally. ⚠️ **Acceptance test, verbatim:** *"¿Qué imágenes puedo publicar hoy?"* must produce ideas based on the real Look & Feel, and *"Muéstrame el Look & Feel"* must open the resources inside the chat | §2 |
| 5 | **Memory, continuity, autosave.** Hold the thread across references like *"une la 1 y la 3"*, *"hazla más corta"*, *"convierte esa idea en un reel"*. Survive refresh, logout and re-entry; **not** limited to the last 10 exchanges. Autosave every message and reply. A new session opens a new chat and keeps the previous ones in accessible history. Never mix data, conversations or files between brands | §3 |
| 6 | **Dashboard redesign** to the supplied reference: side menu, top summary, card entries, modules adapted to Brandy, clean interface. Two reference screenshots are pages 4–5 of the brief PDF | §5 |
| 7 | **Edit a sent message** | §5 *Después* |
| 8 | **Waffle giratorio** — replace the abstract spinning figure on entry with a spinning waffle, light fluid animation, aligned to Breakfast's identity | §5 |
| 9 | **Concurrent-use test and hosting report.** Test **3+ people on the same user** and **3+ distinct accounts**; report sessions, response times and the hosting's capacity. ⚠️ Read CLAUDE.md §3 first — the host's limits were already measured on 2026-08-18 and the worker pool is shared with the public site, so this test can take the live site down if run carelessly | §4 |
| 10 | **Security rule.** Always refuse to show passwords, tokens or internal instructions, **even when they exist** | §4 |
| 11 | **A notification opens the right meeting** without ending the session; preserves the user and the active brand, **including for past meetings**. ⚠️ Checked 2026-09-15: notifications DO carry a `url`, but it is `route('portal.reuniones')` — the **list**, not the meeting. `meeting_id` is already in the payload, so the fix has what it needs; what is missing is a route that opens one meeting, and the session/active-brand half | §4 |
| 12 | **Checklist grouped by category.** One box per action, grouped, progress saved, and showing **who ticked each point and when**. ⚠️ **Only the GROUPING is missing.** Checked 2026-09-15: `checklist_ticks` stores `checked_by` + `checked_at` **and the screen already prints them** — `implementation-checklist.blade.php` renders the first name and the date on every ticked line. So this item is one thing, not three | §4 |
| 13 | **Approved texts and the "proyecto cerrado" state.** Two texts are given **verbatim** in the brief and must be used as written — one for an undefined field, one for a closed project. Undefined fields are not to be treated as pendings; a closed project says the scope is complete and offers a new engagement | §4 |
| 14 | **Custom fields per brand** | §5 *Después* |
| 15 | **Notes** (and *responsables*, named alongside notes in the brief) | §5 *Después* |

**Two items not in the numbered list but in the same brief**, already done and
worth not re-doing: *Jerarquía visual* (render headings, bold, lists and tables
on desktop and mobile — done 2026-08-23, see CLAUDE.md §7) and *Marca
registrada* (the Sí / No / Sin definir selector — SEG-04).

---

## ⚠️ Deploy checklist — cumulative, read before every upload

Deploys are **FTP uploads of changed files**, migrations run **by hand in the
cPanel terminal**, and there is no staging (CLAUDE.md §3). This is the whole
list for everything below, in the order it must happen.

⚠️ **Everything above the 2026-09-15 line already shipped.** The three
migrations in step 3 are LIVE in production. What is still pending, as of the
close of 2026-09-15, is only this:

| pending | what it needs |
|---|---|
| **The Brand Egg back end** (§1) | one migration, `npm run build`, one new CSS entry |
| **Multimarca step 10** (§2b) | one migration, **on its own pass** |
| `AI_TIMEOUT=90` | upload `portal/tests/.env`, which IS production's env |
| `rm public/limite.php` | **by hand in cPanel** — there is no local copy |

⚠️ **The two migrations should not travel together.** The Egg's is additive and
a feature waits on it; step 10's is a destructive column drop that nothing is
waiting on. Stacking them makes one bad evening out of two easy ones.

**1. Upload PHP + `public/build` FIRST. Then migrate.**
Not the other way round. The new code reads `brand_user`; the old code does not
write it. Migrating first opens a window where a signed-in client user belongs
to no brand and the portal closes on them.

**2. Run `npm run build` locally and upload `public/build`.**

| changed | why |
|---|---|
| `dashboard.css` | the brand picker |
| `files.css` | sort bar, video preview |
| `admin.css` | a badge variant |
| `process.css` | the turn byline; dead `.brand-turn-files` removed |
| **`attachments.css`** | **new entry** — chat attachments, both shells |
| **`lightbox.js`** | **new entry** — enlarge an image |
| `assistant.js`, `process-assistant.js` | both import the new `turn-attachments.js` |

⚠️ `turn-attachments.js` is **imported, not an entry** — it does not go in
`vite.config.js`, and adding it there would ship it twice. A stale
`public/build` is a portal with an unstyled picker and chat attachments with no
styling at all.

**3. Run the migrations, in this order:**

```
2026_09_14_100000_create_brand_user_table              # ✅ LIVE — creates AND backfills
2026_09_14_120000_add_source_to_brand_assets_table     # ✅ LIVE — + client_id nullable
2026_09_15_100000_add_attachment_ids_to_message_tables # ✅ LIVE — both message tables
2026_09_15_110000_create_brand_eggs_table              # ⬜ pending — the Brand Egg (§1)
2026_09_15_120000_drop_..._from_users_table            # ⬜ pending — step 10 (§2b), ALONE
```

The first backfills inside the same command, so there is no gap between "table
exists" and "memberships exist". Verified locally: 3 client users → 3
memberships, roles and permission maps intact.

⚠️ **The last one is destructive and irreversible in practice** — its `down()`
restores the columns but cannot restore the data. Nothing is waiting on it, so
it goes on a quiet pass of its own, never bundled with a feature.

**4. No new env keys** — but ⚠️ **`portal/tests/.env` DOES need uploading now**,
for `AI_TIMEOUT=90`. It is not a new key; it is a wrong value live. That file IS
production's env (CLAUDE.md §3), so editing it locally changes nothing until it
travels.

**5. Nothing new depends on cron.** Every state added is derived at read time.

**6. After deploying, check** — one per item, chosen because each is the first
thing that breaks if a piece did not travel:

- a client user can still sign in, and the brand name shows in the portal
  sidebar *(#2 — the pivot and `ActiveBrand`)*;
- `/admin/archivos` opens and a folder sorts by peso *(#3 — the source column
  and the nullable `client_id`)*;
- a turn with an attachment on `/admin/clientes/{marca}/proceso` shows the
  image, and it enlarges *(#4 — `attachment_ids`, `attachments.css`,
  `lightbox.js`)*;
- that same turn shows **who wrote it** *(the byline; `process.css`)*;
- **the Brand Egg row appears on `/admin/clientes/{marca}`**, that screen opens,
  Componer on one ring returns a paragraph, and after Aprobar the link shows on
  `/portal/estrategia` and opens *(§1)*;
- **a client user can still sign in** after step 10's migration *(§2b — the
  columns it drops are inert, so the check is that nothing quietly read one)*.


---

## 1 · Brand Egg — the back end · 2026-09-15

*Plan and detail: `docs/brand-egg.md`. Steps 1 and 3–9 of its §11; step 2 landed
earlier the same day with the toolkit tier.*

### What was there, and what was missing

The front end was real and the back end was not. `BrandEggLayer`, the inlined
`x-brand-egg` drawing and `/admin/clientes/{marca}/brand-egg` all existed, but
that screen was a **bench**: hand-written layer texts in the blade, no table, no
composer, no AI, and — worth noting, because it is why nobody had tripped over
it — **no link to it from anywhere in the app.** The route was reachable only by
typing it.

### What was built

| | |
|---|---|
| `BrandEggState` | four cases, **none of them stored** |
| `brand_eggs` | five TEXT columns from the enum, `generated_at`, `approved_at`, `approved_by` |
| `BrandEgg` model | `text()`, `has()`, `isEmpty()`, `composed()`, `layerTexts()`, `toMarkdown()` |
| `Client` | `brandEgg()`, `brandEggOrNew()`, `brandEggState()` |
| `EggComposer` | one layer per call, dependency order, saved as they land |
| `ClientBrandEggController` | edit · update · compose · approve |
| `Portal\BrandEggController` | the brand's own egg, read-only |
| context | the Egg as tier 1, everything else shifted down |

### The decisions worth keeping

**The two timestamps are not the status column §8 rule 2 forbids.** That rule
governs a single entregable, where a status beside the text is a second truth
that can contradict it. These record two ACTS asked once per egg, and nothing
per-field can disagree with them. **Desactualizado is never written anywhere**:
it is `approved_at` compared against `brand_deliverables.updated_at`, derived at
read time. Stored, it would be a flag every screen that edits an entregable has
to remember to flip — so it would be wrong the first time somebody added a
route.

**One system block for all five layers.** Putting "you are writing the
Personalidad layer" in the system block reads far more naturally and would give
each of the five calls its own prefix: five paid readings of the same
instructions per brand, every time somebody clicks Componer todo. The layer's
name and its sources go in the user turn, and a test pins the prefix
byte-identical across all five. Same mistake the two-phase brandbook read
exists to avoid.

**Five calls, not one, and the run stops itself.** A single generation writing
all five layers is the 60–150s request this host kills at ~182s while starving
the worker pool the public site shares. Each layer is ~20s, each is saved as it
lands, and `EggComposer::BUDGET_SECONDS` ends the run at 150s rather than
starting a call it cannot finish — a partial Egg somebody finishes ring by ring
instead of a 502 with no trace.

**A layer with no written sources is not composed at all, and costs nothing.**
No call is made. An empty layer is honest; a layer of hedging is noise, and
paying a provider to write the hedging is worse.

**⚠️ The Egg took tier 1 and everything shifted down.** `BrandContext::make()`
ksorts the document titles, so the numbers are what hold the hierarchy —
an untitled "Brand Egg…" key would have sorted above "Entregables…" by luck of
its initial today and below an "Archivos…" block the day somebody added one.
One cache miss per brand, once, which is the whole cost.

**An unapproved Egg is still in the assistant's context.** The brief says the
Egg becomes primary *"una vez aprobado"*, which reads as a gate; implemented as
one it would have left every brand alive that day with an assistant that knew
less than it did the week before. Approval changes what the block SAYS about the
content. The one place it genuinely gates is the client's page, which 404s —
never explains — while the Egg is a draft.

### Found on the way

- **The bench screen had no entry point.** Added to the brand page inside the
  *proceso y entregables* card rather than in one of its own: it is the same
  work seen from the top, and a separate card would suggest a second project.
- **A property initialiser cannot call `BrandEggLayer::columns()`** — it is a
  constant expression. `getFillable()` as a method, exactly as
  `BrandDeliverables` already does it, for exactly this reason.
- **`LlmResponse` has no `text()`** — it is `->content`. `Message` has `text()`;
  the two are easy to confuse when writing a new caller.
- **A new Vite entry breaks every test that renders the page** until
  `npm run build` runs, with `Unable to locate file in Vite manifest`. Exactly
  the silent-in-production failure the deploy checklist warns about, caught
  loudly in tests instead.

### How it was proved

`tests/Feature/Ai/EggComposerTest.php` (13), `tests/Feature/BrandEggTest.php`
(16), `tests/Feature/Ai/BrandEggContextTest.php` (6) and
`tests/Feature/PortalBrandEggTest.php` (6). 544 → **588 passing.**

Pinned in particular: the prefix byte-identical across all five layers; the
brand name in the user turn and never in the system block; a layer sent exactly
the entregables its enum names and no others; `brand_deliverables` untouched by
a composition run; Aprobado → Desactualizado with no column written; the tier
order Egg → entregables → toolkit; a bad `{layer}` 404ing before the controller;
a bad compose payload answering JSON rather than a redirect.

### Deploy — ⚠️ read with §3 of CLAUDE.md

1. **Upload PHP + `public/build` first, then migrate.** One migration:
   `2026_09_15_110000_create_brand_eggs_table`. Code shipped ahead of its schema
   takes `/portal` down with a 500 — which is exactly what happened on the
   2026-09-15 deploy, and the lesson was that the two steps are one step.
2. `npm run build` and upload `public/build` **whole, with its manifest**.
   New entry: **`portal-brand-egg.css`**. Changed: `admin-brand-egg.css`,
   `dashboard.css`, `brand-egg.js` (it now imports `assistant-error.js`).
3. **No new env keys**, so the two-env trap does not apply this time.
4. **Nothing new depends on cron.** Composing is a click, approval is a click,
   and every state is derived at read time.
5. **After deploying, check:** `/admin/clientes/{marca}` shows the Brand Egg row
   in the proceso card; that screen opens; Componer on one ring returns a
   paragraph; Aprobar then makes `/portal/estrategia` show the link, and the
   link opens.

---

## 2 · Multi-marca y permisos — ✅ 2026-09-14

*ACC-01/02/03 of the beta review. Plan and detail: `docs/multimarca.md`.*

### What was wrong

`users.client_id` was a single column the whole portal read. A person working
with two brands needed two accounts **and two email addresses**, because
`users.email` is unique.

### The finding that made it safe

**There was no duplicate-email data to merge.** Uniqueness meant one human could
never hold two accounts on one address, so every existing user mapped to exactly
one pivot row. The migration was mechanical, not a reconciliation — which is why
a change touching 21 files landed in one pass.

### What was built

- **`brand_user` pivot** carrying what is true of a person *in a brand*: their
  `BrandRole` there and the sections they may open there.
- **`App\Services\ActiveBrand`** — the one class that decides which brand a
  request is about. Session-held, **re-checked against membership every
  request**, so removing somebody takes effect while their tab is still open.
- **`accessTo($section, ?Client)`** — an optional brand defaulting to the active
  one, which is what let the middleware, both gates, the sidebar and the
  permission grid keep working untouched.
- **A picker** in the portal sidebar, and `POST /portal/marca/{client}`.
- **`InviteUserToClient::attach()`** — an existing account is added to a brand
  rather than duplicated.

### Four things that would have been silent bugs

1. **`canReachBrandAsset()` must ask about the asset's own brand.** Using the
   active brand would make a download succeed or 404 depending on a dropdown —
   a bug that reads as flakiness for a week.
2. **The assistant thread had to be keyed per brand**, or switching replays
   brand A's turns into brand B's prompt: a cross-brand leak inside one person's
   own history, which is the one thing the client assistant is forbidden.
3. **The dashboard thread must stay unscoped.** It stores a brand per turn but
   reads them all back on purpose — *"compará Alea con la otra"* is the question
   it exists for. Filtering by default would have cut it into strands silently.
4. **The archiving mechanism.** Archiving closed a portal because `client()`
   excluded trashed rows. `brands()` keeps that exclusion, so an archived brand
   leaves the picker while a second brand survives.

### Decisions worth revisiting

- **`UserRole`'s two client cases were NOT collapsed** into one `Cliente`. They
  are named in ~25 test sites; rewriting those in the same pass as the storage
  change would have meant one commit where a failure could come from either
  half. `users.role` is now read only for `isBreakfast()`; `BrandRole` decides
  everything brand-related. Collapsing goes with step 10.
- **Removing somebody detaches the membership** and deletes the account only if
  it was their last brand. Deleting the row outright would let the owner of one
  brand close somebody out of another.
- **Breakfast may attach an existing account; a brand owner may not.** An owner
  inviting a known address is refused with *"Ya existe una cuenta con ese
  correo. Pedile al equipo de Breakfast que la agregue a tu marca"* — attaching
  there would tell them an account exists on an address they only guessed at.

  ⚠️ **SUPERSEDED 2026-09-15.** Owners *will* be able to, with the invitee's
  consent — see **Queued · B**. The rule above is what ships until that is
  built, and the reason behind it is what constrains how it gets built: the
  owner must learn nothing either way.

### Verified

21 tests in `MultiBrandTest`, plus the browser: an account owning one brand and
merely a member of another. Switching changed Brandy's brand, collapsed the
sidebar to what they hold there, and flipped the role label to "Miembro".

Two things the browser caught that tests did not: the `<details>` marker was
showing, and the sidebar still printed the *account* role.

### Still open

- **Dropping `users.client_id` and `users.permissions`** — deliberately
  deferred so the migration stays reversible. Dead but still written on account
  creation. This is step 10 of `multimarca.md`.

---

## 2b · Multimarca step 10 — the single-brand columns go · 2026-09-15

*`docs/multimarca.md` §10, closed once the pivot had been live in production for
a full deploy cycle.*

`users.client_id` and `users.permissions` were replaced by the `brand_user`
pivot on 2026-09-14 and left in place so that migration stayed reversible. They
were inert — nothing read them — but the FACTORY still wrote them and copied
them onto the pivot after creating, which is how ~85 test sites declared a brand
membership without ever saying so.

**Two commits, deliberately.** The factory first, with the columns still there
and the suite green; the columns second. One commit would have meant a failure
that could have come from either half — which is the same reason step 10 was
deferred in the first place.

### What changed

| | |
|---|---|
| `UserFactory` | `clientOwner($brand, $permissions)` and `clientMember($brand, $permissions)` write one `brand_user` row each |
| bare `User::factory()->create()` | now a client user in **no brand**, which `accessTo()` fails closed on |
| `User` | `client()` relation gone, both columns out of `$fillable`, the `permissions` cast gone |
| `InviteUserToClient` | stops writing `client_id`; `sendSetupLink()` loses its dead `?? $user->client` fallback |
| `InviteBreakfastStaff` | stops writing `client_id => null` and `permissions => []` |
| `DemoSeeder` | ⚠️ **was never writing the pivot at all** — see below |

### The one real bug this turned up

**`DemoSeeder` had been making brand-less client users since 2026-09-14.** It set
`client_id` and `permissions` and nothing else, so the demo accounts it created
had no membership and `/portal` closed on them. Nothing caught it because seeders
are not covered by the suite and the columns still existed, so it failed silently
and only on a freshly seeded machine. It writes memberships now.

### Reversibility, said plainly

`down()` restores the columns but **not the data, and it cannot** — a person with
three brands has no single `client_id` to go back to. What makes this safe is not
the rollback; it is that the pivot has been the only source of truth for a full
deploy cycle.

### Deploy

⚠️ **This migration is not urgent and should travel alone.** The columns are
inert: leaving them costs nothing but confusion. Do not run it in the same pass
as the Brand Egg upload just because both are pending — a destructive column drop
stacked on a feature deploy makes one bad evening out of two easy ones.

When it does go: upload the PHP first, then
`2026_09_15_120000_drop_single_brand_columns_from_users_table`. The new code
never reads the columns, so unlike the pivot migration there is no gap to worry
about in either order — but the code must not be OLDER than the schema, because
the old code still writes them.

---

## 3 · Leer las imágenes del toolkit sin segunda carga — ✅ 2026-09-14/15

*This one grew in scope during the conversation. It ended up four pieces.*

### What was wrong

A toolkit PDF uploaded to Brandy went to the provider once and **the file was
never kept** — `brand_onboarding_messages.attachments` stores filenames, never
bytes. Putting that same toolkit in the brand's folder meant uploading it a
**second time**. That is the "segunda carga".

### 3.1 · Keeping what is attached

`App\Actions\KeepAssistantAttachments`, used by **both** assistants so a file
kept from the dashboard and one kept from the process board are stored, named
and gated identically.

- ⚠️ **It never throws.** Keeping a copy is a convenience attached to a turn
  that already cost a paid API call; a full disk must not turn a good answer
  into a 500. It also keeps the file when the *provider* fails — the bytes are
  already here and the turn was already paid for.
- **`interno` by default.** Whatever arrives in a conversation is working
  material until a person says otherwise. A wrong "compartido" cannot be taken
  back; a wrong "interno" is one click to fix.

### 3.2 · Where files live

- `marcas/{slug}/referencias/` — beside the deliberate uploads in `assets/`.
- `marcas/_sin-marca/referencias/` — the dashboard assistant's brand dropdown
  can be empty, so a file can arrive belonging to no brand.

⚠️ **The underscore is not decoration.** Slugs are lowercase letters, digits and
hyphens, so no brand can ever be called `_sin-marca` — a collision would put one
brand's files inside another's folder over FTP, where nothing would warn anybody.

⚠️ **`brand_assets.client_id` is now nullable.** An unfiled file is Breakfast's
alone: `canReachBrandAsset()` answers on `isBreakfast()` before anything else,
and `scopeSharedWithClient()` excludes it even when marked compartido.

### The structural call

**One table, one list, a `source` column** — not a second table.

Dividing files by what they *feed* is exactly what `context_documents` did, and
it was deleted for hiding files from the people who needed them (CLAUDE.md §11).
`AssetSource` says where a file came from; `AssetVisibility` still decides who
sees it, separately. Provenance and audience are different questions and must
never collapse into one.

### 3.3 · The file manager

Sorting by fecha/nombre/peso/tipo (links, so the order is in the URL and
survives a reload), video previews, the "Sin marca" folder, a provenance badge.
Copy-link already existed on every row.

**🐛 A bug that shipped green.** Sorting by size looked right in tests and was
wrong in the browser:

```
order by "created_at" desc, "size_bytes" desc, "id" desc
```

`Client::brandAssets()` is declared `->latest()`, so the sort was appended
*behind* `created_at` and never decided anything. It passed because factory rows
share a timestamp to the second, tie, and fall through to the real sort. The
browser never ties.

Fixed with `->reorder()`. The test was rewritten with distinct timestamps where
the newest row is the *smallest*, then **confirmed to fail against the old
code** before the fix was accepted. Now CLAUDE.md trap 17.

### 3.4 · Describing images, and the toolkit reaching Brandy

The read now returns **two halves**: `"digest"` is what the material *says*;
`"visual"` is what it *looks like*, stored under
`### Cómo se ve (descripción, no definición)` inside the same per-file section,
so re-reading a file replaces both.

**The rule it had to not break.** `onboarding_prompt` says *"En una imagen lees
lo que está ESCRITO… Leer no es reconocer"* — and that rule is **general**, it
governs every image, not just typefaces. It also lives in **only that prompt**:
Brandy's own prompt and the dashboard prompt carry no image rule at all, which
is right, because only the extractor turns a reading into brand data.

The visual half does not weaken it. Describing a page is allowed; deciding that
what you see *is* the brand's typeface is not, and nothing in the visual half
reaches `proposals`.

**🐛 A bug only a live call could find.** A new JSON key is exactly what a model
quietly ignores, so a real image went to the real provider:

```
digest empty? YES
visual empty? no
isEmpty()     TRUE  <-- would be discarded
```

`DocumentReading::isEmpty()` asked the digest alone. A page of pure graphics —
a Look & Feel spread, **precisely the material this was built for** — has no
text to quote, so it would have been thrown away with a perfectly good
description attached. Now asks both halves.

**Then the tier that makes it reachable** (`brand-egg.md` step 2). Block 2 is
now four numbered tiers:

```
1. Entregables de la marca
2. La marca (ficha)
3. Proceso del proyecto
4. Toolkit de la marca (respaldo, NO es la fuente principal)
```

⚠️ **The numbers are load-bearing.** `BrandContext::make()` ksorts the titles,
so ordering came from the alphabet — E, L, P landed right by luck of their
initials. A document added later called "Archivos…" would have outranked the
entregables silently.

The tier also **states what it is in its own first lines**, not only in the
house prompt: "below" is an ordering a model can lose track of in a long prompt,
and the framing cannot be separated from the text it governs.

`versionFor()` now takes the later of the deliverables' and the client's
timestamp — it is printed into block 2, so it has to move when block 2 moves.

### Verified live, with a toolkit that contradicted an entregable

| asked | answered |
|---|---|
| the claim — *only* in the toolkit | gave it, **and** said it is not in the entregables yet |
| the colour — toolkit azul, entregable mostaza | **mostaza**. The entregable won |
| how does the toolkit look | from the visual digest, **and volunteered the contradiction** |

All three rules holding at once: uses the backup, never lets it outrank a
reviewed entregable, names a conflict instead of resolving it.

The guard held too: a brand with a toolkit and zero entregables still reports
`hasUsableContext()` false. The assistant is never offered on the strength of a
document nobody reviewed.

### Still open

- **Brandy cannot describe a stored image on demand** — only what the digest
  captured at read time. Re-sending a stored file to the model is possible but
  costs the full file per turn on a host that kills at ~182s. Not built, and
  not obviously wanted.

---

## 4 · Mostrar imágenes en el chat, ampliar y reproducir video — ✅ 2026-09-15

### What was wrong

You attached a screenshot, Brandy answered about it, and **the picture vanished
from the conversation.** The transcript rendered `{{ $turn->body }}` and nothing
else, so on reload you got an answer discussing an image nobody could see.

The names were already stored and already replayed to the model as
`[Adjuntó: captura.png]` — so Brandy remembered the file. The SCREEN was the
only thing that forgot.

### The shape

Three transcripts over two tables: Brandy on `/portal` and the dashboard
assistant (`assistant_messages`), and the onboarding assistant on the process
board (`brand_onboarding_messages`). Only the third showed anything at all, and
only bare filenames.

- **`attachment_ids`** on both message tables. ⚠️ **A second column rather than
  a new shape for `attachments`**: that one is read by `toLlmMessage()` and goes
  into the prompt, so turning it into a list of objects would change what the
  model is told to solve a problem the model does not have. Names are what the
  model needs; ids are what the screen needs.
- **`App\Services\TurnAttachments`** — the one class that decides what an
  attachment becomes, so three transcripts cannot disagree about the same file.
- **`x-turn-attachments`** + `attachments.css` + `lightbox.js`, shared by all
  three.

### Decisions

- **Video is a link that opens in a new tab, never an inline player.** A chat
  thread is a bad video player and a tab gives the browser's own controls free.
  The preview is `<video preload="metadata">` — the browser paints the first
  frame itself, so there is no poster to generate and no ffmpeg, which shared
  hosting does not have. ⚠️ Never `preload="auto"`: a transcript of videos would
  drag every file through the gated route in full just to draw the thread.
- **Previews only for our own files.** An external link (YouTube, Vimeo) is a
  plain new-tab link. A third-party thumbnail would mean the page fetching an
  image from someone else's server, and would need a carve-out in the rule
  below. Nobody asked for it.
- ⚠️ **THE CLIENT'S OWN ATTACHMENTS ARE NOW KEPT** — a **third** place the
  client side writes anything (CLAUDE.md §11). Narrow on purpose: the row lands
  `interno`, so the brand never sees it in its own Archivos, only Breakfast
  does. Without it the client's half of the chat could never show a picture,
  which is most of the point.

  ⚠️ **This is the interim, not the answer.** Reviewed 2026-09-15: a pasted
  screenshot is not a brand asset, and filing it under the brand is what made
  this a third client write path at all. It belongs in a folder that belongs to
  the PERSON — see **Queued · A**.

### The rule this had to not break, and why it does not

`assistant-text.js` deliberately does not honour images or outside links:
*"every one of them is a way for a language model to put something on the page
that nobody designed."*

That governs **what the model writes**, not what a person attaches. Two
different things wear the word "image":

| | chosen by | |
|---|---|---|
| an attachment | a person, who picked the file | safe — show it |
| a URL in a reply | the model, which wrote the string | this is what the rule is for |

Asked during this work whether the rule blocks *"find an image of a room that
would look like my brand style"*. **It does not — and neither does anything
else, because Brandy has no way to look.** There is no tool use anywhere in
`app/Services/Ai`: `complete`, `stream`, `json`, and nothing that searches. Asked
for a picture she would invent a URL, and the rule stops that invention being
painted onto a client's screen as though it were a reference. **Agreed to leave
it that way**: no web search, and she describes the room instead, which is
legitimately hers to recommend.

### Fails closed, twice

- A file the viewer **may not open** renders as a bare name, never as a broken
  image. An attachment row is a listing, so printing one the viewer cannot
  download would tell them the file exists — the same split as
  `scopeSharedWithClient()` versus the gate.
- A **deleted** file leaves its name in place rather than shifting every
  attachment after it onto the wrong name. Positional, and pinned by a test.

### Verified

7 tests, plus the browser on both shells. A seeded turn carrying one kept image
and two orphaned names rendered exactly three states — thumbnail with lightbox,
and two dashed chips. The lightbox opened as a real `:modal` dialog with the
right `src` and caption.

⚠️ **The token check that matters**: `attachments.css` renders in BOTH shells,
so every token it uses was read back from a live element in each. `--rule`,
`--surface` and `--ink-quiet` are **not on `:root`** — they come from
`admin.css`/`dashboard.css` — which is exactly how `permissions.css` shipped
broken on `--box-edge`. All six resolve in both.

### The live half — finished the same day

The turn you just sent now draws its own files, before any reload.

⚠️ **This turned out to be a correctness fix, not a polish one.** The live
turn was appending `[Adjunto: captura.png]` **into the visible text** — a line
the server never renders. So the turn you had just sent and the same turn after
F5 said two different things, and neither knew about the other. The bracket text
is gone; both paths now draw the file.

- `resources/js/turn-attachments.js` builds the same markup from the `File`
  objects still in the browser, because the asset ids do not exist until the
  request returns. ⚠️ **The Blade component is canonical**; this one mirrors it
  and says so in its own header. Two renderers for one thing is a drift risk,
  and the mitigation is shared class names plus that rule.
- The process board had its OWN live renderer (`brand-turn-files`, a row of
  bare names) which the server had already stopped emitting. Both composers use
  the shared one now, and the dead CSS is deleted.
- Object URLs are revoked on `pagehide`, not when an element goes: they are the
  src of something on screen for as long as the page lives, and revoking one
  early would replace a thumbnail with a broken image — the exact failure this
  item exists to remove.

**Verified in the browser** with a real attach-and-send (the provider stubbed,
since what was under test was the DOM): the live turn showed the image from a
`blob:` URL, carried no bracket text, and opened in the lightbox with the right
caption. The dialog closed and released its `src`.

### Still open

- Nothing on this item.

### Addendum — who said this (2026-09-15)

Came out of the #5/#6 discussion rather than the item itself, but it is the same
screens and the same idea: **the transcript should say what actually happened.**

The `/proceso` thread belongs to the BRAND, not to a person — everyone at
Breakfast working that brand writes into the same conversation, because it is
the record of how the entregables got written rather than somebody's private
chat. So "was this me or Andrea" is a real question there, and the screen was
not answering it.

**Nothing had to be migrated.** The column, the relation and the helper all
existed already; only the view was missing. Checked before building: **5 of 5
user turns already had an author, 0 missing.** The assistant's 5 turns have no
`user_id`, which is correct — she is not a person.

- `x-turn-author` — monogram plus full name, reusing `.admin-avatar` so a
  person looks the same here as on the staff roster and the account screen.
- Three cases: **a person** (initials + name), **Brandy** (a placeholder mark),
  and **author gone** — a deleted account or a row from before `user_id` was
  recorded, which keeps its place and says only what is known.
- `onboardingMessages()` gained `->with('author')`: a shared thread has several
  authors, and without it a long transcript is one query per turn.

⚠️ **Brandy's mark is a deliberate placeholder.** Her avatar was being drawn
while this was built, so her span is exactly the final size and shape and says
in a comment that it becomes an `<img>` when the artwork lands — two swaps
(Blade + JS), and the layout does not move.

**Two things picked up on the way:**

- The **live turn was unsigned** while the reloaded one was signed — the exact
  inconsistency fixed for attachments an hour earlier, in the other renderer.
  `process-assistant.js` now mirrors `x-turn-author`, which stays canonical.
- **`/clientes/nueva` never got `x-turn-attachments`** when item 4 wired it.
  Same markup, same loop, missed. A draft's turn carrying a brandbook was
  showing nothing.

---

## 5 + 6 · Chat history and the redesign — assessed 2026-09-15, not built

Assessed together **because they are one piece of work**, and recorded here
because the decisions were made before any code was.

### Why they merged

#5 is three things, and only one of them is UI:

| | needs the mockups? |
|---|---|
| splitting "what Brandy remembers" from "what the screen shows" | no |
| `conversations` as an entity — the table, what starts one | no |
| the side panel that browses them | **yes** |

The cap is currently **one number for both**, on purpose: `scopeThread()` limits
to 20 turns and both blades call it with a comment saying *"THE SAME NUMBER SHE
READS — the transcript on screen IS her memory."* If you can scroll back to a
question she can no longer see, you will ask a follow-up about it and get a
confused answer. **Full history breaks that invariant**, so it needs a
replacement: a visible line where her memory ends, or a new conversation
resetting it. That is a design decision the redesign has to carry.

### The wider system asked for

Summaries of long conversations, a warning before the limit, a menu of
conversations, a shareable reference, and a meter showing how full the context
window is.

**What already exists:** `ai_usage_logs.prompt_tokens` records the real prompt
size of every request, reported by the provider. The meter needs no estimator.
**What is missing:** the denominator — `config/ai.php` has `max_tokens: 8000`,
but that is the *output* cap. There is no context-window size anywhere.

### Four constraints, decided

1. ⚠️ **The meter never touches the prompt.** A number that changes every turn,
   above block 3, drops cache hits to zero silently at ~150× the cost — the
   exact reason the spend digest had to be moved. It is a UI value in the JSON
   response.
2. ⚠️ **A summary is written once and never revised.** Replacing older turns
   rewrites the bytes right after block 2, so the whole prefix misses. Rare is
   fine; a *rolling* summary would make every single request a cache miss.
3. ⚠️ **No identifier may unlock content.** The first design — "paste a
   conversation id and the agent reads it" — was rejected outright, and rightly:
   a system where an id is a key is one bug away from leaking. **The person
   picks from a menu of their own conversations**; the id never arrives as text,
   from a message or a request body. The URL is for humans to share, and opening
   it hits a normal authorised route.
4. **Summarisation was rejected once and is now justified** — but only just.
   The earlier objection was cost, and cost is still not a reason. A hard
   context ceiling is. The failure mode named then still stands: a lossy summary
   can drop the list that *"une la 1 y la 3"* refers to. So it is a last resort
   at a real limit, visible when it happens, with starting a fresh conversation
   as the alternative.

### Two things confirmed while assessing

- ⚠️ **The current architecture has no id→content hole.** Raised as a concern,
  checked, and it does not exist: there is **no tool use anywhere** in
  `app/Services/Ai` (`complete`, `stream`, `json`, nothing that looks anything
  up), and every thread read is keyed server-side to the authenticated user or
  to a route-bound brand the middleware already guarded. No id has ever come
  from a request.
- **Brandy has no web search, and that is deliberate.** Asked whether the
  no-outside-images rule blocks *"find an image of a room that looks like my
  brand"*: it does not — nothing does, because she cannot look. Asked for a
  picture she would invent a URL, and the rule stops that invention being
  painted on a client's screen as if it were a reference. **Agreed to keep it
  that way**: she describes the room instead, which is legitimately hers to
  recommend.

### ✅ The data model, decided 2026-09-15

**A conversation belongs to a PERSON and records which brand it is about** —
nullable, because the dashboard's «todas las marcas» mode has no brand.

That keeps both behaviours that exist today:

- the **portal** always creates a brand-scoped conversation, so what the model
  sees never crosses brands;
- the **dashboard** may create one with no brand, so *"compará Alea con la
  otra"* still works inside a single thread.

The menu lists the conversations you own. Ownership is the column; the brand is
a fact about the conversation, not its owner.

**Scope note:** the process board stays out of #5. Its thread belongs to the
brand and is shared by everyone working it; giving it per-user conversations
would break that by accident.


---

## 7 · Editar una pregunta enviada — ✅ 2026-09-15

**Built as recall, deliberately not as editing.** Breakfast's ask was to edit a
question already sent. Editing a sent turn IN PLACE means rewriting the history
the model already answered from: two versions of one question, an answer
attached to the version nobody can see any more, and a thread that no longer
records what was actually asked. Pressing **Up** in the composer puts the sent
text back in an empty box instead — the same gesture, none of that. The sent
turn stays exactly as sent; what you edit is a NEW question.

### Where it lives, and why there

`resources/js/assistant-composer.js`, which is the one module all three panels
share — the same reason Enter's rules live there. A key that did different
things on different screens would be worse than one that did nothing.

⚠️ **Up and Down are only taken over at the EDGES of the box.** The field is a
textarea, so those keys are how somebody moves between the lines of a long
question. On the first line there is nowhere up to go, so recall is free;
anywhere else, taking the key would trap the caret and make a multi-paragraph
question impossible to edit.

Edits made while walking the list are kept, the way a shell keeps them, and
sending throws them all away.

### The bug that was in it

Typing a new question while standing on an old recall wrote the new text into
**that entry's** working copy, so a later Up served back something nobody had
ever sent. Sending now re-syncs the scratch copies from what was actually sent
— which is what a shell does when a line is accepted.

Found by driving the real module in Node against a stubbed textarea rather than
by reading it. ⚠️ **That harness is not in the repo**: there is no JS test
runner here, and adding one is a project-shaping decision nobody has made. It
is worth re-creating if this file is touched again.

### ⚠️ The other half — a stop button — is NOT built, and is blocked

Breakfast's flow was "cancel the running answer, edit, resend". **There is no
stop button and adding one naively would be a lie**, because:

1. Cancelling an OpenAI-wire-format call means closing the connection, and that
   only means anything while **streaming**.
2. **PHP cannot tell the browser hung up while blocked on a socket** —
   `connection_aborted()` only updates when PHP writes output, and during a
   blocking Guzzle call it writes nothing.
3. `remember()` runs after the answer lands regardless of who is listening, so
   the "cancelled" question **and its answer** would still be written and would
   reappear on the next load.

Streaming fixes all three, and `LlmClient::stream()` and
`BrandAssistant::streamAnswer()` are **already built and wired to nothing**
(CLAUDE.md §7). But it is gated on whether this host lets PHP flush at all,
which `tools/flush-probe.php` exists to measure and which has not been run.
`Server: openresty` with gzip on says probably not.

**Until then the flow is: wait for the answer, press Up, edit, resend** — which
is the same thing minus the impatience, and honest about what the host allows.
⚠️ `AI_TIMEOUT=90` bounds that wait, and production still carries **300** until
`portal/tests/.env` is uploaded.

### Done alongside, off the list

**The canonical tag named whatever host served it.** `url()->current()` reads
the request host, so every alias certified itself as the original — and this
site answers on two hostnames sharing one document root with `robots.txt`
allowing everything, so every public page was two indexable copies. Now built
from `APP_URL` + `getPathInfo()`. It lands on the most-rendered layout in the
app: seven public pages plus the six entrance screens.
`tests/Feature/CanonicalUrlTest.php`, verified to fail against the old code
before being kept.

---

## 9 · Uso simultáneo e informe de hosting — 🟡 2026-09-16

*Brief §4: test 3+ people on the same user and 3+ distinct accounts; report
sessions, response times and the hosting's capacity.* **The capacity half is
measured. The session half is not.**

### The number

⭐ **30 concurrent requests, account-wide.** Not per site — for everything on
the account at once.

| test | result |
|---|---|
| 29 holds + 1 homepage = **30** | all **200** |
| 30 holds + 3 homepage = **33** | 30 × 200, **3 × 508** in ~0.62s |

That is CloudLinux LVE's **EP (entry processes)** ceiling, confirmed by
`/proc/self/cgroup` reporting `/lve65549`. `lveinfo` and `lveps` are root-only
on a shared account, so it could not be read — it had to be found by ramping
3 → 6 → 12 → 20 → 30 and watching for the first refusal.

### What the ramp showed on the way up

| concurrent | distinct PIDs | spawn spread | result |
|---|---|---|---|
| 3 | 3 | 12ms | all 200, each held exactly 5.000s |
| 6 | 6 | 63ms | all 200 |
| 12 | 12 | 192ms | all 200 |
| 20 | 20 | 492ms | all 200 |
| 30 | 30 | 451ms | all 200 — **but everything else got 508** |

**Nothing ever queued.** Every request got its own `lsphp` process and held for
exactly the time asked. LiteSpeed spawns workers on demand — one at idle, thirty
inside half a second — and the account's own idle baseline is a single `lsphp`
at ~45MB RSS.

⚠️ **So concurrency was never the problem, and the old note saying "3 concurrent
short requests all fine" understated it by an order of magnitude.** Response
times barely moved: 1.05s baseline, 1.13–1.24s at five, 1.35–1.69s at ten. What
breaks this host is **duration**, not count: thirty *short* requests are
invisible, while a handful of *long* ones consume the same slots for as long as
they run.

### ⚠️ 508 is the status nobody was looking for

Over-limit is **HTTP 508 in ~0.6 seconds** — immediate refusal, not a timeout,
and not the 502/503 the August note recorded. CloudLinux rejects the request
before PHP runs at all, so there is **no Laravel handler, no `laravel.log` line,
no `ai_usage_logs` row**: the same four silences as trap 5 wearing a different
number.

**508 = account at 30 concurrent · 503 = the proxy gave up · 500 = PHP died.**
Three faults that look identical to a person.

### ⛔ Streaming is impossible, and that is now settled

A probe flushing one line per second for ten seconds — PHP's own timestamps
prove it flushed on time — arrived at the client **entirely within 0.41s, at the
end**. 2KB of padding per chunk, 20KB total, never broke the proxy buffer.

So `LlmClient::stream()` and `BrandAssistant::streamAnswer()`, both already
written, **can never be wired here**, and **an honest stop button cannot be
built** (CLAUDE.md §7 has the chain). Item 7's Up-arrow recall is the answer
instead. Do not re-open without re-running the probe.

### ⚠️ What this says about our own gates

`LimitConcurrentAiTurns` allows **2** AI turns at once against a ceiling of 30.
That looks conservative by a factor of fifteen, and it is not: an AI turn holds
its slot for up to 90 seconds, so 30 of them would lock **every site on the
account** for a minute and a half. The gate is sized for duration, not count,
which the measurement now justifies rather than merely asserting.

### ⚠️ One thing measured that contradicts a live setting

**`max_execution_time` is 60s for the web SAPI**, not the ~180s in the old note
— that figure was the *proxy's* patience, not PHP's. `AI_TIMEOUT=90` is
therefore longer than PHP's own execution limit.

It may still be fine: PHP does not count time spent in system calls and socket
waits toward `max_execution_time`, and a Guzzle call is exactly that. But it is
**not established**, and production currently carries `AI_TIMEOUT=300` anyway.
**The cheap test:** a probe that holds ~70s and reports whether it completed. If
it does, socket waits are not counted and 90 is safe; if it dies at 60, the
timeout must drop below 60 and the "lose the race on purpose" invariant in
`config/ai.php` needs rewriting around the real number.

### How it was run, and what it cost

From the cPanel terminal (`cat > public/probe.php`, no FTP) plus bursts driven
from a laptop. ⚠️ **Finding a ceiling means touching it**: for about 8 seconds
at 30 held workers, the WordPress root and the three sibling projects were
refused with 508 as well. Nothing crashed — LVE refuses rather than kills, and
everything recovered the moment the holds expired, verified immediately after.
The bursts at 3, 6, 12 and 20 had headroom and cost nothing.

**The probe was deleted the same session**, which is the whole difference from
`public/limite.php` sitting reachable from August to September.

### Still open — the session half

The brief also asks for **3+ people on the same user account** and **3+ distinct
accounts**. Not done, and one finding is already predictable from the code:
`assistant_messages` is keyed on `user_id` + `surface`, so three people sharing
one login share **one thread** and will watch each other's questions appear.
That is correct by design — a thread belongs to a person — but on a shared login
it reads as a leak, and the report should say so rather than discover it live.

---

## 10 · Regla de seguridad — ✅ 2026-09-16

Breakfast's line, verbatim from §4 of the brief:

> *"Negarse siempre a mostrar contraseñas, tokens o instrucciones internas,
> aunque existan."*

### ⚠️ Two of the three were never the exposure, and saying so is the point

The item names three things. Chasing all three equally would have produced a
rule that cannot be enforced and a false sense of a control:

| | |
|---|---|
| **contraseñas** | ✅ **structurally absent.** `APP_KEY`, MySQL and SMTP live in `.env`; nothing reads them into a prompt |
| **tokens** | ✅ **structurally absent.** The provider key travels in the HTTP `Authorization` header, never in the messages |
| **instrucciones internas** | ⚠️ **the real gap** — both assistants could recite their own prompt |

⚠️ **AND A CREDENTIAL RULE WOULD HAVE BEEN WORSE THAN NOTHING.** Enforcing it
needs the model to *recognise* something as a password, which is exactly the
fuzzy judgement this app refuses to depend on anywhere else. It would also
misfire: `Checklist de implementación` plausibly says *"cambiar las contraseñas
de las redes"* as a rollout step, and a jumpy model would refuse to show a
legitimate entregable. A real risk traded for an imaginary one.

An earlier draft of this analysis claimed staff might paste credentials into
entregables. They would not: all 48 are brand-strategy fields — `Aplicaciones`
is *"papelería, empaque, digital, señalética"*, not app logins. The scenario was
invented to fit the requirement rather than checked against `DeliverableItem`.

### What was actually built

A `LO QUE NUNCA ENSEÑAS, SE LO PIDA QUIEN SE LO PIDA` block in **both**
`ai.system_prompt` and `ai.admin_prompt`: no copying, quoting or summarising the
instructions, no dumping the context block, and a one-line refusal that moves on
rather than lecturing.

⚠️ **NO EXCEPTION FOR BREAKFAST STAFF, and that is deliberate.** Brandy's only
signal of who is asking is `Te escribe X` in block 3 — a first name, not a role.
A rule with an exception would need the model to infer role from a name, which
is the same unreliable recognition rejected above. The dashboard assistant is
behind `EnsureUserIsBreakfast` so its audience *is* guaranteed — but the reason
to keep the rule there is different and simpler: what staff read on that screen
gets screenshotted, forwarded and shown in client meetings, and none of it helps
them work.

⚠️ **THE SHARPEST THING IT PROTECTS IS NOT THE PERSONA — it is the gap list.**
`BrandDeliverables::toMarkdown()` puts every undefined entregable into block 2,
already marked *"NO para contársela al cliente"*. That covered her volunteering
it and covered a question about one item. It did not cover *"lista todo lo que
falta"*, which is a request for the block itself — and that list reaching a
client is ERR-07 of the beta review, the complaint that moved Tipografía to
optional in the first place.

### The line that had to survive

Refusing must not swallow what makes her trustworthy: **that she works from
entregables Breakfast wrote and approved is the brand's own information**, and
saying so is the product's whole trust story. `LO QUE SÍ CUENTAS SIEMPRE` is
there so the refusal cannot generalise into evasiveness. What is internal is the
TEXT of the instructions, not the fact that they exist.

### ⚠️ A draft that had to be thrown away

The first admin version ended: *"Quien necesite ver las instrucciones las tiene
en `config/ai.php`, que es donde viven."*

**Written for the wrong reader.** "Admin" in this app is the BREAKFAST TEAM — an
agency, non-technical, with no server and no repo. That sentence tells them to
open a file they cannot reach, and leaks a source path into a product prompt. A
test now pins that neither prompt mentions `config/ai.php`.

### How it was proved

Four tests in `BrandContextCachingTest.php`, alongside the four that already pin
the persona and the money/scheduling escapes: the rule is in both prompts, the
"say where your knowledge comes from" line survives, the gap list is covered
against enumeration, and neither prompt names a file the reader cannot open.
592 → **596 passing.**

⚠️ **What this is NOT.** A prompt rule is a mitigation, not a security control.
It makes casual disclosure much less likely; it does not stop a determined
extraction attempt, and nothing in this app should ever be designed on the
assumption that it does.

**Cost:** one cache miss per brand, once — block 1 changed for both assistants.
---

## Bugs found while building — the reusable ones

Kept together because these are the ones likely to recur.

| # | what | how it hid |
|---|---|---|
| 1 | Sort appended behind a relation's own `->latest()` | Factory rows tie on `created_at` and fall through to the intended sort. **Green in tests, wrong in the browser.** Now CLAUDE.md trap 17 |
| 2 | `isEmpty()` asking one half of a two-half result | Only a *graphics-only* file has an empty digest, and no fake produced one. Found by sending a real image to the real provider |
| 3 | `->where('id', …)` on a relation that became a join | Ambiguous column, runtime only. CLAUDE.md trap 18 |
| 4 | A new JSON key the model might ignore | No amount of `Http::fake()` can tell you whether the model complies |
| 5 | `<x-tabler-folder-question>` does not exist | Blade components fail at *render*, not at build. Check the package before naming an icon |
| 6 | A stale docblock: *"a client user belongs to exactly one brand"* | Comments do not fail. Found while editing the portal assistant panel for item 4, three items after multi-marca made it untrue |
| 7 | `/clientes/nueva` missed when the transcripts gained attachments | Two screens share the same loop and only one was wired. Nothing failed — the draft screen just quietly showed nothing |
| 8 | Live turns unsigned while reloaded ones were signed | Twice in one day, in two different renderers. **Any live-rendered turn has a server-rendered twin, and they drift unless something says which is canonical** |

**The pattern in 1, 2 and 4:** a fake tells you your code does what you wrote.
It cannot tell you the world agrees. Anything touching a provider, a relation's
own defaults, or a package's contents wants one real run.

---

## Open questions

### Still open — only Breakfast can answer these

They are about what the Brand Egg MEANS, not about how it is built.
(`brand-egg.md` §13.)

1. **Layer 5's "Personalidad"** — layer 2's *output*, or the same sources layer
   2 reads? Built as a dependency for now, because the other reading gives two
   layers restating Arquetipos + Valores.
2. **Layer 4's "Brand Assets"** — the brand's files, or the graphic entregables
   (Emblemas, Brand universe, the two identificativos)? Decides whether layer 4
   waits on image understanding or ships with the rest.
3. **"Tagline"** — is it `Claim`? There is no `Tagline` entregable, and `Claim`
   is the only candidate. Assumed, and the cheapest of the three to be wrong
   about.

### Decided — do not reopen

| | decision | when |
|---|---|---|
| **Brand Egg colours** | ⚠️ **Already answered on 2026-09-11 and recorded in `brand-egg.css`**: the egg's palette is the artwork, stays exactly as Corel drew it, and gets **no light plane** in either theme. The dark-page outline was explicitly anticipated — *"a change to ask for, not to smuggle in here as a background."* It was re-asked by mistake on 09-15; the answer has not changed | 2026-09-11 |
| **Conversation ownership** | **A conversation belongs to a PERSON and records which brand it is about**, nullable — the dashboard's «todas las marcas» mode has no brand. The menu lists your own; what the model sees stays brand-scoped on the portal. Nothing that works today stops working | 2026-09-15 |
| **Owner invites** | **Owners may add someone who already has an account, with that person's consent.** The owner always sees «invitación enviada», so they learn nothing about which addresses exist; the invitee accepts or does not. Replaces the current Breakfast-only rule | 2026-09-15 |
| **Brandy has no web search** | Deliberate. She cannot look anything up, so asked for a picture she would invent a URL. She describes instead | 2026-09-15 |

---

## Queued — raised during the cycle, not on the original list

### A · Per-user file folders

Raised 2026-09-15, in place of a simple yes/no on keeping the client's chat
attachments.

**The conflict it resolves:** a screenshot somebody pastes into a chat is not a
brand asset. Filing it under the brand — which is what happens today — is what
made it a third client write path and what made the question uncomfortable. It
belongs to the **person**.

**What it becomes:** a folder per user in the file manager, holding what they
pasted and — the reason this matters — **whatever the agent generates for them
later.** She generates no images or video today; she will.

Touches: `brand_assets` gains an owner dimension beyond `client_id`, the storage
layout gains a per-user root, the file manager gains user folders beside brand
folders, and someone has to decide who sees another person's folder.

⚠️ **Until it is built, the current behaviour stands**: a client's attachment is
kept under the brand as `interno`, so the chat can show the picture. That is the
interim, not the answer.

### B · Invite-with-consent

From the decision above. The owner invites; if the address already has an
account, **that person** is notified and accepts before any membership row is
written. Neither the message nor the timing may differ between "existed" and
"did not", or the enumeration leak comes back through the side door.

---

## Producción · «No obtuve respuesta.» — 2026-09-15

**The report.** A client's brand saw three "No obtuve respuesta." in a row on
`/portal`, one per question. Reported as "the agent is not answering".

**What it actually was.** Not the assistant. `bootstrap/app.php` narrowed
`shouldRenderJsonWhen` to `api/*`, and this app has no `api/*` — every
JavaScript-driven endpoint lives under `admin/` or `portal/`. The client's
session had passed its eight-hour lifetime (`breakfast-session`,
`Max-Age=28800`), so every POST to `/portal/asistente` returned **419 wearing
Laravel's HTML "Page Expired" page**. In `assistant.js`,
`response.json().catch(() => ({}))` turned that HTML into `{}`, and the
deployed bundle — older than `assistant-error.js` — printed the success path's
fallback string instead of the status. Trap 13, exactly as written, on the one
surface nobody had checked.

**Why it took a day.** The request dies at the CSRF middleware, so:

| where we looked | why it was empty |
|---|---|
| `laravel.log` | no exception was ever thrown — 419 is a normal response |
| `ai_usage_logs` | the provider was never called |
| `assistant_messages` | `remember()` is after the answer, and there was none |
| `public/error_log` | no PHP fatal; newest entry was 18-Aug |

Four independent silences with one cause. Hypotheses eliminated on the way, all
by evidence and each worth not re-running: `AI_TIMEOUT=300` overriding the 90s
invariant (real, and still worth fixing, but not this); OpenRouter credits (a
live `admin-question` turn at 11:30 proved the provider healthy); an OOM killing
the worker after headers; a full disk quota (38 GB of ∞); an oversized brand
context (8,656 bytes, digest empty); a deployed bundle missing the
`Accept: application/json` header (present in all three).

**What proved it.** `curl -si -X POST https://vamosdebreakfast.com/portal/asistente`
with `Accept: application/json` — unauthenticated, from anywhere — answers
`419` with `content-type: text/html`. No browser and no client account needed.
Keep that command; it is the fastest test of this whole class of bug.

**Fixed.**

1. `bootstrap/app.php` — `$request->is('api/*') || $request->expectsJson()`.
   Laravel's own default, restored. A browser navigating still gets the HTML
   error screen, which is what that screen is for.
2. `npm run build` — the deployed bundle predated `assistant-error.js`, so a
   419 now reads *"La sesión caducó. Recarga la página y vuelve a enviarlo.
   (419)"* rather than a lie.
3. `tests/Feature/JsonErrorResponseTest.php` — pins the content type on both
   sides, and pins that a navigation still gets HTML.

**Deploy:** `bootstrap/app.php` and the whole of `public/build/`. ⚠️ The build
output changed shape — `assets/js/assistant.js`, unhashed, where production has
`assets/assistant-CLAXlJ6s.js`. **The directory must go up together with its
manifest**, or the page asks for files that are not there. The old hashed
assets can be swept afterwards; three stale `assistant-*.js` had accumulated,
because FTP deploys never remove anything.

**Found on the way, not fixed here:**

- ⚠️ `public/limite.php` is still on production — the Aug-18 probe from §3. It
  allocates 512 MB on demand and is publicly reachable, on a worker pool shared
  with the public site. Delete it.
- `/home/orustrav/public_html/error_log` is **264 MB** of one WordPress
  plugin's deprecation warnings, still growing on every request to that site.
  Truncate with `: > …/error_log`. Disk is unlimited, so it is untidiness
  rather than danger — but it is I/O on a shared host.
- Production runs `history($user)` where local runs `history($user, $client)`:
  the per-brand thread scoping from CLAUDE.md §5 is **not deployed**. A
  multi-brand user currently replays one brand's turns into another's prompt.
- Production `.env` has `AI_TIMEOUT=300`, defeating the 90s invariant in
  `config/ai.php` whose whole purpose is to lose the race on purpose and fail
  *inside* Laravel. Not today's bug; still wrong. Both env files need it.

### Deploy of 2026-09-15 — the whole cycle went up

Ten days of local work shipped in one pass, deliberately, after the fix above.
It worked, with two stumbles worth keeping:

**1. The page reloaded instead of sending.** Production was still on a build
from before the stable-filename change in `vite.config.js`, so the cached HTML
and the compiled blades referenced the old hashed asset names
(`assets/assistant-CLAXlJ6s.js`). `assistant.js` never executed, the composer
form fell back to a native POST, and every click looked like a reload loop —
one cause, not two. `php artisan view:clear` plus a hard refresh fixed it.
⚠️ **It is a one-time transition, not a design flaw.** Stable filenames are
deliberate (FTP uploads to a fixed address) and cache-busting lives in
`Vite::createAssetPathsUsing()` as a `?v=<mtime>` stamp. Clients get the new
HTML on their next navigation because it is served `no-cache, private`; only a
tab already open needs a reload.

**2. Clients got a 500 on `/portal` for the gap between upload and migrate.**
`Table 'orustrav_breakfast.brand_user' doesn't exist`, thrown while rendering
`portal/home.blade.php`. Exactly what step 3 of the checklist above exists to
prevent: the upload happened, the migration did not, and `/admin` kept working
throughout because it does not read the pivot. Resolved by
`php artisan migrate --force`; the backfill wrote 4 memberships.
**The lesson is procedural, not technical — the two steps are one step.**

**Verified after:** `brand_user` present and backfilled, brand files intact at
`storage/app/private/marcas/` (five brands), `.env` still production on MySQL,
Brandy sending.

### Still open at the close of 2026-09-15

| | what | why it matters |
|---|---|---|
| ⚠️ | **`rm public/limite.php`** | the Aug-18 probe, still publicly reachable, allocates 512 MB on demand on the worker pool shared with the public site. **Security, not tidiness.** ⚠️ **It exists ONLY on production** — there is no local copy to delete and commit, so this is a cPanel File Manager or FTP action by hand, and nothing in the repo will ever remind you of it again once this line goes. |
| ✅ | ~~**`AI_TIMEOUT=90` in BOTH env files**~~ | **Done locally 2026-09-15.** `portal/.env` and `portal/tests/.env` both carry 90 now. ⚠️ **It does not take effect until `tests/.env` is FTP'd up** — that file IS production's env, and editing it locally changes nothing live. |
| | **Truncate `/home/orustrav/public_html/error_log`** | 264 MB of one WordPress plugin's warnings, growing on every request to that site. Disk is unlimited, so it is I/O and noise rather than danger. |
| | **Run the four post-deploy checks** | sidebar brand name, `/admin/archivos` sort by peso, attachment turn shows and enlarges, turn byline |
| | **Sweep stale `public/build/assets/assistant-*.js`** | the old hashed bundles; FTP never removes anything |

**Where the work resumes:** ~~the Brand Egg back end~~ — **built on 2026-09-15,
steps 1 and 3–9; see §1 above.** What is left of that plan is step 10 alone,
layer 4's images, which is gated on brief point 2 and not on anything here.

~~`docs/multimarca.md` §10~~ — **closed 2026-09-15.** `users.client_id` and
`users.permissions` are gone; see §2b. What is left of that plan is collapsing
`UserRole`'s two client cases into one, which is vocabulary rather than storage
and belongs in its own pass.

---

## Producción · «No se pudo contactar al asistente» (401) — 2026-09-16

A client on `/portal` sent the same question to Brandy **ten times over twelve
minutes** and got the same sentence every time. Staff on `/admin` were being
answered normally in the same minute, which is what ruled out the provider, the
API key, the worker pool and the network in one step.

### How it was found, and why the app's own log was useless

`laravel.log` had nothing since the previous day — correctly. The turn died at
the **auth middleware**, so no controller ran: nothing in `laravel.log`, nothing
in `ai_usage_logs`, nothing in `assistant_messages`. The same four silences as
the 2026-09-15 incident above, one middleware earlier.

What broke the case open was the **number on screen**. `assistant-error.js`
prints the status after the sentence, and the client's screenshot said `(401)`.
That is the entire reason that file exists, and it paid for itself here:

| where | what it said |
|---|---|
| `~/access-logs/vamosdebreakfast.orustravel.org` | ten `POST /portal/asistente … 401`, one `POST /admin/asistente … 200` between them |
| `sessions` table, by the client's IP | a single row, `user_id` **NULL**, `last_activity` matching the last failed POST to the minute |

⚠️ **The access log is the account's, not the app's, and it is not named after
the domain** — the file is `vamosdebreakfast.orustravel.org`. `tools/production-log.sh`
only reads Laravel's own log, so for anything that dies below Laravel the path
is `~/access-logs/*` in the cPanel terminal. Grep all of them; guessing the
filename wastes a round trip.

⚠️ **The clock caught us once here too.** The access log stamps `-0400`; the app
runs `America/Guayaquil` (`-05:00`). `13:25 -0400` in the access log is the
`12:25` in `sessions.last_activity`. They are the same event.

### What it was

**The client was signed out.** The session row existed and held no user, so
`Authenticate` threw, and because `bootstrap/app.php` renders JSON for anything
that `expectsJson()` (the 2026-09-15 fix) the panel got a clean `401` — which
was **not in `MEANING`**, so it fell through to the generic sentence that says
nothing. A person cannot act on "no se pudo contactar"; they retry.

### Two things fixed

**1. A 401 now says so, and moves the page.** `assistant-error.js` gained the
`401` entry and `handleSignedOut()`, wired into all three `!response.ok`
branches (`process-assistant.js` ×2, `assistant.js` ×1). It waits 2.5s so the
sentence can be read, then goes to `/login`. No `?redirect=` parameter —
Fortify takes the intended URL from the session, and a query string it does not
honour would only look like it worked.

**2. `www` is now redirected to the bare domain**, in `public/.htaccess`.
`www.vamosdebreakfast.com` served the app in its own right — the client's
referrer was `https://www.vamosdebreakfast.com/portal` while the working admin's
was `https://vamosdebreakfast.com/admin`. With `SESSION_DOMAIN=null` the session
cookie is **host-only**, so the two hosts kept two separate sessions and signing
in on one left the other signed out.
⚠️ **NOT fixed with `SESSION_DOMAIN=.vamosdebreakfast.com`**, which was the
obvious move and is wrong: this document root also serves `breakfast.drpixel.app`,
and that cookie domain does not match it — it would have signed out every user
of the second domain to fix the first.
⚠️ **The rule is host-conditional and belongs in `public/.htaccess`, not the
root one** (trap 9): the 24KB root file is shared with the WordPress site and
three sibling apps, and `breakfast.drpixel.app` must not be sent to the client's
domain.

### What is NOT established

**Why CSRF let the request through.** `PreventRequestForgery` sits outside
`Authenticate` — curl with no token gets 419, as it should — so the client's
token matched a live session. A plain expiry cannot do that; it produces 419.
There is no CSRF exemption in `bootstrap/app.php` and no `AuthenticateSession`
in the stack. **The mechanism is still open.** It does not change either fix,
and both are right regardless, but it means the *frequency* of this is unknown.
If it recurs, that is the thread to pull.

### Deploy

Both changes are dead until uploaded:

1. `npm run build` — **done**; upload `public/build/`.
2. Upload `public/.htaccess`. ⚠️ Verify with
   `curl -sI https://www.vamosdebreakfast.com/portal` → must be `301` to the
   bare domain. Before this it was a `302` to `https://www.vamosdebreakfast.com/login`,
   staying on `www`.
3. No migration, no env change.
