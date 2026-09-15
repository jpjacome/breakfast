# The app, end to end — a guide for a new agent

**Read `CLAUDE.md` at the repo root first.** That file is the *rules*: the
invariants you must not break and the traps that have already cost somebody a
day. This file is the *territory*: what exists, where it lives, and how a
request gets from a URL to a screen. Neither replaces the other, and when they
disagree, CLAUDE.md wins.

Accurate as of **2026-09-15**. If you change something this file describes,
change the file in the same pass.

---

## 1. The sixty-second version

One Laravel 13 app serving **two products from one codebase**:

- a **public marketing site** at `/` — yellow-and-black, GSAP, never themed;
- a **portal**, `/admin` for the agency and `/portal` for its client brands —
  light/dark, invitation-only.

The portal exists for one reason. Breakfast writes each brand into **48
structured entregables**, and a language model (**Brandy**) answers questions
using *only* those. Everything else — the permission model, the two-phase
brandbook read, the prompt-cache discipline — is scaffolding around that one
idea: **the model must not invent brand attributes.**

```
136 PHP classes · 38 test classes · 29 migrations · 20 stylesheets · 13 JS files
```

---

## 2. How a request actually happens

Worth reading once, because three things here are unusual.

```
routes/web.php
   │
   ├─ public         → Route::view(), no controller at all
   ├─ /admin         → auth · breakfast · covers-client
   ├─ /portal        → auth · section:<slug>
   └─ /archivos/{a}  → auth only, and BELONGS TO NEITHER GROUP
```

**1. `covers-client` sits on the whole `/admin` group** and no-ops unless the
route names a `{client}`. That is deliberate: every brand-scoped route added
later is guarded the day it is written, not the day somebody remembers.

**2. The asset route is outside both groups.** A link pasted into an entregable
has to work for the Breakfast person writing it and for the client reading it,
so one URL answers both and `User::canReachBrandAsset()` decides who gets the
bytes.

**3. Missing access is a 404, never a 403.** A member who was never given a
section should not learn the section exists.

Middleware aliases are registered in `bootstrap/app.php`:

| alias | class | job |
|---|---|---|
| `breakfast` | `EnsureUserIsBreakfast` | keeps clients out of `/admin` |
| `covers-client` | `EnsureStaffCoversClient` | an `equipo` user reaches only their assigned brands |
| `section:<slug>[,write]` | `EnsurePortalSectionAccess` | gates one portal page |
| `ai-turn` | `LimitConcurrentAiTurns` | bounds how many AI turns run **at once** |

⚠️ `bootstrap/app.php` renders JSON only for `api/*`. A failed
`$request->validate()` on an `admin/` or `portal/` route returns a **redirect**,
which `fetch()` reads as a silent failure. Any JS-driven endpoint needs a
FormRequest with `failedValidation()` overridden — `AdminAssistantRequest` is
the model.

---

## 3. Every route

### Public — `resources/views/site/`

`/` · `/nosotros` · `/servicios` · `/podcast` · `/carta` · `/contacto` (+ POST,
`throttle:5,1`) · `/legal/privacidad` · `/legal/terminos`

These are all `Route::view()` apart from the contact POST. One public page has a
controller:

| | | |
|---|---|---|
| `GET /brand-egg` | `BrandEggMapController` | which entregable feeds which Brand Egg layer |

⚠️ **Public but UNLISTED, and the two words do different jobs.** *Public*: no
`auth`, because nothing on the page belongs to a brand or a user — it renders
the shape of the model, derived from `BrandEggLayer` and `DeliverableItem`, and
dereferences no user (which `<x-layouts.app>` would, hence the site shell).
*Unlisted*: the layout is passed `:noindex`, and **nothing in the site links to
it**, so it is reachable only by someone given the URL. Breakfast's decision of
2026-09-14, so the mapping can be reviewed without an account.

It publishes the 48 entregables, which is Breakfast's own method — so anything
added to that screen is added in public. `BrandEggMapTest` pins the no-login
path, the noindex tag and the absence of links to it.

⚠️ Its stylesheet **declares its own role tokens**. `--surface`, `--rule`,
`--ink-quiet`, `--sunken` and `--accent` come from `admin.css`/`dashboard.css`,
which a public page never loads — and `brand-egg.css` asks for `--accent`. Same
trap as `permissions.css` and `--box-edge`, one shell further out.

### Shared

`GET /archivos/{asset}` — the one file route both sides use.

### `/admin` — `auth` + `breakfast` + `covers-client`

| route | controller | |
|---|---|---|
| `GET /admin` | `DashboardController` | usage + spend + the assistant |
| `POST /admin/asistente` | `AdminAssistantController` | `throttle:20,1` + `ai-turn` |
| `GET /admin/orbe` | *(view)* | design bench for the assistant orb |
| `GET·POST /admin/clientes[/nueva]` | `ClientController` | list, create, store |
| `POST /admin/clientes/nueva/guardar` | `ClientDraftController` | autosave; creates the draft row |
| `POST /admin/clientes/nueva/asistente` | `BrandOnboardingController@draft` | `throttle:10,1` + `ai-turn` |
| `POST·DELETE /admin/clientes/papelera/{slug}…` | `ClientController` | restore / purge — **raw slug**, because binding 404s on a soft-deleted row |
| `GET·PUT·DELETE /admin/clientes/{client}` | `ClientController` | the brand's ficha |
| `DELETE /admin/clientes/{client}/borrador` | `ClientController@discardDraft` | |
| `…/{client}/usuarios[/{user}]` | `ClientUserController` | store, update, resend, destroy |
| `GET·PUT /admin/clientes/{client}/proceso` | `ClientProcessController` | **the 48 entregables + 3 steps** |
| `POST …/proceso/paso` | `ClientProcessController@step` | |
| `POST …/proceso/asistente` | `BrandOnboardingController@store` | `throttle:10,1` + `ai-turn` |
| `GET /admin/clientes/{client}/brand-egg` | `ClientBrandEggController@edit` | **a bench** — the real drawing over hand-written layer texts, until `brand_eggs` exists |
| `GET /admin/reuniones[/nueva]` | `MeetingController` | roster + month calendar |
| `…/{client}/reuniones[/{meeting}]` | `MeetingController` | store, update, cancel, destroy |
| `GET /admin/archivos[/{client}]` | `FileManagerController` | one folder per brand; sortable by fecha/nombre/peso/tipo |
| `GET /admin/archivos/sin-marca` | `FileManagerController@unfiled` | ⚠️ declared **before** `{client}` or the binding claims the word |
| `POST·DELETE·PATCH …/{client}/archivos[/{asset}]` | `Admin\BrandAssetController` | **the only write path for files** |
| `GET /admin/cuenta` + `…/dos-pasos*` | `AccountController`, `AccountTwoFactorController` | |
| `/admin/equipo[/{user}]` | `StaffController` | the Breakfast team itself |

⚠️ **The file manager has no write routes of its own.** It posts to
`clients.assets.*`, shared with the process and brand screens — a folder filled
from two doors that disagree is a folder nobody trusts. Those routes answer with
`back()` so a click returns where it came from.

### `/portal` — `auth` + `section:<slug>`

| route | gate | |
|---|---|---|
| `GET /portal` | — | `Route::view('portal.home')` |
| `POST /portal/asistente` | — | Brandy. `throttle:20,1` + `ai-turn`. **The brand comes from `$user->activeBrand()`, never the request** |
| `POST /portal/marca/{client}` | — | switch brand (ACC-02). No section gate — switching is not access to anything |
| `POST /portal/avisos/leidos` | — | the notification bell |
| `GET /portal/estrategia` | `section:estrategia` | the brand, written from filled entregables |
| `POST /portal/estrategia/checklist` | `section:estrategia` | **a client write** — see §6 |
| `GET /portal/reuniones` | `section:reuniones` | |
| `GET /portal/brand-assets` | `section:brand-assets` | |
| `/portal/equipo[/{user}]` | `section:equipo` | owner-only, full CRUD |
| `GET /portal/perfil` | `section:perfil` | **a client write** — name + password, posting to Fortify |

⚠️ The block comment above this group in `routes/web.php` still says *"The
section pages are stubs for now"*. **That is stale** — every section has a real
controller. Nothing is stubbed.

---

## 4. The database

30 migrations. `users`, `cache`, `jobs` are Laravel's.

| table | what it is |
|---|---|
| `clients` | **a brand, not a person.** name, slug (unique), industry, status, contact, notes, `onboarded_at`, `document_digest`, `trademark_registered`, soft-deletes |
| `users` | role, 2FA columns. ⚠️ `client_id` and `permissions` are **dead** — kept only so the multi-marca migration stays reversible |
| `brand_user` | ⭐ **which people are in which brands**, with their `BrandRole` and permissions map **per brand**. Unique on `client_id` + `user_id` |
| `client_staff` | which Breakfast people cover which brands |
| `brand_deliverables` | **one row per brand, 48 TEXT columns.** The heart of it |
| `client_process_steps` | when each of the three steps started / closed |
| `meetings` | title, agenda, `scheduled_at`, link, notes, `created_by`, `cancelled_at` |
| `meeting_reminders` | unique on `meeting_id` + `window` — why a reminder never goes twice |
| `checklist_ticks` | `client_id`, `item_key`, `checked_at`, `checked_by` |
| `brand_assets` | disk + path + mime + size + **`visibility`** + **`source`** (subida/referencia). ⚠️ `client_id` is **nullable** — a file attached to Brandy with no brand chosen belongs to none |
| `brand_onboarding_messages` | the brandbook conversation; `attachments` are **names, never bytes** |
| `assistant_messages` | `user_id` + `surface` + role + body + `attachments` (names) + `attachment_ids` (which assets they became) |
| `ai_usage_logs` | the per-request ledger — tokens, cache split, `cost_micro_usd`, latency, `context_fingerprint` |
| `notifications` | Laravel's, driving the portal bell |
| `passkeys` | table exists; the Fortify feature is commented out |
| `brand_profiles` | ⚠️ **dead.** The old 42-field profile. Unread, still on disk |
| `context_documents` | dropped 2026-08-14, folded into `brand_assets` |

Three things to know about this schema:

- **48 real columns, not JSON and not 48 rows.** `DeliverableItem::Relato->value`
  **is** the column name — one vocabulary, no mapping table. A 49th entregable
  is a migration, and that cost is accepted because the taxonomy comes from a
  closed document.
- **No status column anywhere.** Filled is done, empty is pending. A separate
  status is a second truth somebody must remember to move, and it can contradict
  the content. Same reasoning as `users.permissions` having no "none" level.
- ⚠️ **Timestamps are Ecuador time, not UTC** (`America/Guayaquil`). Never
  convert on the way to a view. Rows written before 2026-08-14 are still UTC and
  read five hours late.

---

## 5. The domain

### Models — `app/Models/`

`Client` `User` `BrandDeliverables` `ClientProcessStep` `Meeting`
`MeetingReminder` `BrandAsset` `ChecklistTick` `AssistantMessage`
`BrandOnboardingMessage`

```
Client (a brand)
├── users               the brand's people (brand_user pivot: role + permissions)
├── staff               Breakfast people assigned to it (client_staff)
├── deliverables        hasOne — the 48
├── processSteps        hasMany — the 3
├── meetings            hasMany
├── brandAssets         hasMany
├── checklistTicks      hasMany
└── onboardingMessages  hasMany
```

Two idioms worth copying: **`deliverablesOrNew()`** — a brand with no row is the
normal case, so every reader gets an object rather than a null check; and
**`scopeVisibleTo(User)`** — one scope decides what a staff user may see, used
everywhere rather than re-derived.

⚠️ **The slug never follows a rename.** It is the brand's address in bookmarked
URLs and in `storage/app/marcas/{slug}`, so `clients.update` writes every other
column and leaves it alone.

### Enums — `app/Enums/`

Each carries its **own presentation** — `label()`, `description()`, `icon()`,
`badgeClass()`. There are no lookup arrays in views. Copy this.

| enum | |
|---|---|
| `UserRole` | `admin` · `equipo` · `cliente_owner` · `cliente_miembro` |
| `PortalSection` | 5 cases: 3 grantable, 1 owner-only, 1 always-on |
| `AccessLevel` | Read / Write. **There is no "none"** — absence is the representation |
| `DeliverableItem` | the 48. 19 obligatorios, 29 opcionales |
| `ProcessStep` | Arquitectura → Territorio → Toolkit |
| `ClientStatus` · `AssetVisibility` · `ReminderWindow` | |

⚠️ **Two enums lie about themselves on purpose.** `ProcessStep::Arquitectura`
displays as *"Identidad de marca"*, and `PortalSection::Estrategia` as *"Tu
marca"* (`BrandAssets` as *"Archivos"*). Only `label()` moved — the **value** is
in the database and in URLs, and changing it would be a hand-run migration to
alter a word on screen.

### Actions — `app/Actions/`

Anything two controllers both need.

`AdvanceProcessStep` (the **only** class that writes steps) · `DeleteClient` ·
`EnforceBrandPermissionCeiling` · `InviteUserToClient` · `InviteBreakfastStaff` ·
`NotifyAboutMeeting` (the **only** thing that notifies a meeting) ·
`StartBrandDraft` · five Fortify actions.

### Services — `app/Services/`

`Checklist` + `ChecklistItem` — the only thing that decides what a checklist
item *is*. An item's key is a hash of its **normalised text**, not its position:
reordering keeps every tick, **editing a line drops its tick**, and that is
intended — a rewritten item is a different item.

### Commands — `app/Console/Commands/`

`meetings:remind` (every 15 min) · `ai:health` · `mail:health` ·
`deliverables:export-schema`

⚠️ **The scheduler cron may not exist on the host.** `meetings:remind` is the
first thing in the app that genuinely depends on it, and it is written to
survive: it sends windows whose moment has already *passed*, skips ones that
passed too long ago, and a unique index makes a double run a no-op. Never make
correctness depend on a scheduled job having run.

---

## 6. Permissions, in one place

```
Breakfast grants the owner → the owner grants their team → nobody gives
what they were not given.
```

Stored as `users.permissions` JSON, `{section_value: access_level}`.

- **grantable (3)**: Estrategia, Reuniones, Brand assets. **Read or absent —
  there is no client write level, ever.** `grantCeiling()` caps what may be
  passed on; `accessTo()` clamps a stored `write` on the way out, so legacy rows
  are inert without a migration.
- **owner-only (1)**: Equipo. Granted by role, never stored in the map.
- **always-on (1)**: Perfil, write, everybody.

Lowering an owner re-clamps their whole team **in the same request**
(`EnforceBrandPermissionCeiling`).

⚠️ **Every one of these questions is asked PER BRAND** (ACC-01). The same
person can own one brand and be a member of another, so `accessTo()`,
`canRead()`, `isBrandOwner()` and `grantCeiling()` all take an optional
`?Client` that defaults to `ActiveBrand`. See
[multimarca.md](multimarca.md).

⚠️ **Nothing outside `User` reads the `permissions` map, and nothing outside
`User` touches `client_staff`.** One class decides; everything else is a thin
reading of it. `Gate::define('read'|'write')` in `AppServiceProvider` delegates
to the same helpers so `@can` works in Blade.

### The client writes in exactly two places

The sentence *"the client side has no write path"* appears in enough old
comments to be worth contradicting loudly:

1. **Perfil** — their own name and their own password, posting to Fortify.
   The **address is not editable anywhere**: it is the account's identity and
   the only way back in through a reset link.
2. **The implementation checklist** on `/portal/estrategia`. The checklist's
   *text* is one of the 48 entregables and stays Breakfast's; the **ticks** are
   the client saying which lines they have done. Writes to `checklist_ticks` and
   **never** to `brand_deliverables`. A posted key not in that brand's own
   checklist is refused, so nobody can invent items.

⚠️ **Breakfast staff can open `/portal`** and they are in no brands, so any
portal page that dereferences `$user->activeBrand()` flatly is a 500 for them.

---

## 7. The AI layer — `app/Services/Ai/`

### There are two assistants and they never share a builder

| | reads | surface |
|---|---|---|
| **`BrandAssistant`** (Brandy) | ONE brand, from its entregables | `/portal/asistente` and the dashboard panel |
| **`AdminAssistant`** | ALL brands, from the database | `/admin/asistente` |

Separate context builders on purpose, never an `if` inside one: the admin's
crosses brands by design, which is exactly what the client's is forbidden to do.
See `docs/asistente-admin.md`.

```
Contracts/LlmClient          ← provider-agnostic
Providers/OpenAiCompatibleClient
BrandContextRepository       ← the ONLY class here that touches Eloquent.
                               Block 2 is FOUR numbered tiers — 1 entregables,
                               2 ficha, 3 proceso, 4 toolkit (respaldo). The
                               numbers hold the order: make() ksorts titles.
BrandContextBuilder          ← assembles the message array
Admin/AdminContextBuilder · PortfolioSnapshot · MentionedBrands · SpendDigest
Brand/DeliverableExtractor · DeliverableSchema · ProposalReconciler
Data/  Attachment BrandContext Message LlmResponse TokenUsage …
Concerns/FinishesTruncatedAnswers
UsageRecorder · UsageStatistics · AssistantFailure · OpenRouterAccount
Exceptions/  LlmException → ProviderUnavailable · RateLimited · InsufficientBalance …
```

### ⚠️ The prompt-cache invariant — the single most breakable thing here

Message order **is** the caching mechanism:

```
block 1   house system prompt      stable across ALL clients
block 2   brand context            stable per client
block 3   the question             volatile
```

DeepSeek caches on an exact prefix match **with no markers**. Anything volatile
above block 3 — a timestamp, a user name, a request id, a *"responde en
{idioma}"* hint — drops the hit rate to zero **silently, with no error**. The
only signal is `usage.prompt_cache_hit_tokens` sitting at 0, and costs go up
~150×.

Gemini needs an explicit breakpoint instead: `Message::cacheableSystem()`. Only
the **last** stable block is marked — a breakpoint is a boundary, so marking
block 2 caches block 1 with it.

Three things live in block 3 for exactly this reason, and each has a test:
**today's date**, the **AI spend digest** (reading it writes a usage row, so in
the prefix it would change the prefix on every request), and **attachments**.

**There is a test asserting prefix stability. If you change a builder and it
fails, the test is right.**

### Two providers, one wire format

**DeepSeek** (default, text-only, cheap) and **OpenRouter → Gemini 3.5 Flash
Lite**. OpenRouter exists for one reason: **DeepSeek cannot read PDFs**, and
brandbooks arrive as PDFs. Gemini takes a PDF as a file part directly — which is
why there is no PDF-parser dependency in this codebase. Production runs
`AI_PROVIDER=openrouter`.

### Reading a brandbook is TWO phases

`BrandOnboardingController` answers an upload with a **read** — files go up once,
the model returns a digest, stored in `clients.document_digest` — and then the
browser asks for **proposals a slice at a time**, four calls of twelve
entregables over that stored text.

Why: reading a PDF and writing 48 proposals in one generation runs 60–150s, and
§9 below says what the host does to a request like that. Each call is now ~20s.
A *typed* turn is unchanged — it was never the problem.

⚠️ **The digest and the slice go in block 3.** A per-brand system block would
give each of the five calls its own prefix and turn one cached read into five
paid ones. `BrandbookReadingTest` pins it.

### Two gates, doing different jobs

Every AI route carries **both**. `throttle:20,1` (`10,1` where files are
accepted) because every turn is a paid call and the send button is one keystroke
away. And `ai-turn`, because a rate limit counts requests **per minute** and
cannot bound how many run **at once** — twenty 90-second requests inside one
minute is within `throttle:20,1` and is also every PHP worker gone.

**A new AI surface needs both. An ungated one is a bill; an unbounded one is an
outage.**

### Costs

`ai_usage_logs` is a ledger in **integer micro-USD** (no float drift). The
provider's own reported cost wins when there is one — OpenRouter reports the
actual charge per response, which already accounts for the real cache split.
The price tables in `config/ai.php` are the DeepSeek fallback. **Nothing else in
the codebase hardcodes a price.**

### Output formatting

A reply is formatted **on the way to the screen**, never by asking the prompt for
a format. `resources/js/assistant-text.js` is the one place: bold, italic, code,
paragraphs, `h3`–`h5`, lists, pipe tables in their own scroller, and internal
paths as links. Three reasons it is not in the prompt: the prompt is block 1 of a
cached prefix; a model asked politely for a format complies *most* of the time,
which is the worst kind of most; and the models write markdown regardless.

⚠️ **Nodes, never `innerHTML`** — the input is a language model's output.
Blockquotes, images and outside links are deliberately not honoured: they are
ways for a model to put something on the page nobody designed.

---

## 8. The front end

Hard rules, from the project owner. Violating them is a real error.

- **One `general.css`** for the site AND the app: palette, base, type sizes, the
  one radius (36px), the one border (2px), the dark theme.
- **One stylesheet per blade**, holding only that page's own elements. A page
  never ships another page's CSS.
- **Never invent a colour.** The `--bkf-*` palette is the whole vocabulary. If
  something seems to need another, **ask**.
- **Spacing is plain rem.** No tokens, no scales. Type is `clamp(px, vw, rem)`.
- **No role tokens.** Dark mode is two variables swapping: `--page` and `--ink`.
- **Less is always more.** Write only the CSS in use.
- **Self-describing names.** No `v2-` / `sq-` prefixes, no jargon.
- **Spanish** for UI text, route URLs and enum labels. **English** for class
  names, methods, variables, comments.

### Layouts

| shell | for | props |
|---|---|---|
| `<x-layouts.app>` | `/admin` | `:css`, `:scripts` |
| `<x-layouts.portal>` | `/portal` | same |
| `<x-layouts.site-remake>` | the public site **and the six entrance screens** | `:css` |

`:css` takes a name or a list. A page owns one stylesheet *and* names any
component stylesheet it renders — that is how a shared file reaches a page.
`:scripts` merges into the single `@vite` call; **calling `@vite` twice emits the
dev client twice**.

⚠️ **There is no `<x-layouts.auth>`.** It was a second, unstyled shell for the
entrance screens and was deleted. `/reset-password` is where the invitation mail
lands — the first page of this app a client ever opens.

### Component stylesheets — the third category

Three files today:

`permissions.css` (the grid renders on `/admin/clientes/{marca}` **and**
`/portal/equipo`), `checklist.css` (both sides) and `brand-egg.css` (the
drawing, which the client's read-only view will render inside the portal shell
as well). ⚠️ `admin-brand-egg.css` is NOT one of them — it is the admin
screen's own page stylesheet, and the two are kept apart deliberately; the
header of each says which half it owns.

⚠️ **A file rendered in both shells may only use tokens BOTH shells define.**
That is not theoretical: `permissions.css` shipped broken on `--box-edge`, which
only the portal had.

### Scripts

Entries in `vite.config.js`: `app.js` (the public site's GSAP bundle) ·
`assistant.js` · `process-assistant.js` · `process.js` · `client-draft.js` ·
`checklist.js` · `copy-link.js` · `orb-demo.js`.

The rest are **ES modules imported by those**, not entries:
`assistant-composer.js` (auto-grow textarea; Enter sends on desktop,
Shift+Enter newlines; touch-aware) · `assistant-text.js` · `assistant-error.js` ·
`assistant-orb.js` · `assistant-recorder.js`.

⚠️ **A recorded voice note is re-encoded to WAV in the browser.** Chrome's
MediaRecorder produces `audio/webm`, and `Attachment` refuses webm deliberately
because the provider 400s on it — so the obvious implementation breaks on the
commonest browser. Mono 16 kHz WAV is the only format that works everywhere.

⚠️ `orb-demo.js` is its own entry so **three.js never ships to someone reading
the podcast page**.

### Blade traps

- ⚠️ **Never write a directive name inside a Blade comment.** Comments are
  stripped *after* directives compile, so `{{-- see the @php block --}}` opens a
  real PHP block and the page dies. Cost an hour once.
- ⚠️ **A `use` statement in a nested `@php` block is a parse error.** Blade
  compiles it in place; inside an `@if`, PHP refuses it. Fully qualify instead.
- Icons are `<x-tabler-{name}>`. No Tailwind — hence the custom pagination view.

---

## 9. The host, and what it allows

**Shared hosting (iFastNet, cPanel). Deploys are FTP uploads of changed files.**
No CI, no `git push` to deploy. Measured against the live server, not guessed:

| | |
|---|---|
| Idle wall clock | fine to ~180s; **the request is killed at ~182s** |
| Memory | ~450MB granted; PHP's own `memory_limit = 512M` bites first |
| Uploads | 800M post / 512M file — never the constraint |
| **After a 150–300s request** | **everything behind it is refused in ~15s with 502/503** |

That last row is the one that matters. **Every domain on this account shares one
small pool of PHP workers.** A long request holds one, and once enough are held
the front end answers the public site, the portal *and* `/login` with a 503.

⚠️ **The failure is invisible from inside.** The worker dies before Laravel's
handler runs, so **nothing reaches `laravel.log`**. An empty log next to a
failing screen means the failure was *below* Laravel — read the access log.

Three things exist because of this and must not be relaxed without re-running
the probe: `LimitConcurrentAiTurns`, `AI_TIMEOUT=90` (lose the race on purpose
and fail *inside* Laravel where it can be logged), and the two-phase read.

### Consequences

- **No queue worker runs.** `QUEUE_CONNECTION=database`, nothing drains `jobs`.
  Anything `ShouldQueue` **silently never happens**. Notifications are sent
  synchronously.
- **The cron may or may not exist.** Derive state from data at read time; let a
  job do side effects (mail) only.
- **Vite's watcher ignores `public/**`** because the project sits in OneDrive,
  which locks files while syncing and kills the dev server with EBUSY.

### ⚠️ Three env files, and only one travels

| file | what it is |
|---|---|
| `portal/.env` | **local** — sqlite, `MAIL_MAILER=log` |
| `portal/tests/.env` | **PRODUCTION.** Live `APP_KEY`, MySQL, SMTP. Gets uploaded. **Not a test fixture** |
| `portal/.env.deploy` | FTP credentials. Git-ignored. **Never uploaded** |

**Any new service means editing both** of the first two, and the live one only
takes effect once it is FTP'd up. This is the step that gets forgotten.

`tools/production-log.sh` downloads and tails the live log over the same FTP
account — `--list`, `--grep`, `--single`. Downloads land in
`Breakfast/logs-produccion/`, outside the repo, so production's log cannot be
pushed back to production by a careless upload.

**Mail:** `info@vamosdebreakfast.com` is the one address for everything. cPanel
SMTP, port 465, SSL. An earlier draft used `hola@` — that address is wrong
everywhere.

---

## 10. Tests

Pest 5, `tests/Feature/`, named for the screen. 35 feature classes, ten of
them under `Ai/`.

```bash
composer test         # config:clear, then artisan test
vendor/bin/pint       # formatting
composer dev          # serve + queue:listen + vite, concurrently
```

The AI ones are the load-bearing set — `tests/Feature/Ai/`:

`BrandContextCachingTest` (**prefix stability**) · `BrandbookReadingTest` (the
read and a batch share one prefix) · `AdminAssistantTest` · `AssistantMemoryTest`
· `ConcurrentTurnsTest` · `DeepSeekClientTest` · `UsageRecorderTest` ·
`UsageStatisticsTest` · `OpenRouterAccountTest` · `BrandOnboardingTest`

⚠️ **The test suite reads your local `.env`.** `phpunit.xml` pins the DB, mail,
queue — **and `AI_PROVIDER` and the OpenRouter keys**, because it did not once:
switching provider on a laptop sent the client tests past their `Http::fake()`
to the **real API with the real key**, spending credits and shipping test
prompts to a provider. The only symptom was assertion failures that looked like
ordinary breakage. `Http::preventStrayRequests()` now runs before every test.
**Pin anything a test's behaviour depends on.**

---

## 11. Where to add a new…

**…page in the portal.** Add a `PortalSection` case *and build its screen in the
same pass*. Five week-one stubs were deleted precisely because they filled the
sidebar with "esta sección se construye en la siguiente fase" and put checkboxes
in front of the owner for pages nobody could open. Old slugs left in
`users.permissions` are ignored — the map is read by walking the enum.

**…brand-scoped admin route.** Put it in the `/admin` group with `{client}` in
the URL and `covers-client` guards it for free.

**…AI surface.** `throttle` **and** `ai-turn`. Route it through `UsageRecorder`.
Keep everything volatile in block 3. Add a caching test.

**…stylesheet.** One file, named for the page, added to `vite.config.js`, named
in the blade's `:css`. If it renders in both shells it is a *component*
stylesheet and may only use tokens both shells define.

**…entregable.** A migration plus a `DeliverableItem` case. The case value is
the column name.

**…colour, radius or spacing token.** Don't. Ask.

---

## 12. What is not built, and what is dead

**Not built:** subscriptions / Stripe (`docs/suscripciones.md`) · the Brand
Egg's BACK END — no `brand_eggs` table, no `BrandEgg`, no `EggComposer`, and
nothing of the Egg reaches either assistant's context yet; its front end does
exist (`BrandEggLayer`, `x-brand-egg`, and `/admin/clientes/{marca}/brand-egg`
as a bench with hand-written texts), so `docs/brand-egg.md` §11 steps 2, 3 and
5–10 are what is left · PDF/DOCX extraction in `BrandContextRepository` (only
`.md`/`.txt` are read; PDFs are skipped with a warning, and
`unreadableDocuments()` surfaces them so nobody assumes a brandbook is feeding
the assistant when it is not) · passkeys (Fortify feature commented out until
there is a UI).

**Dead or stale, do not trust:**

- `Breakfast/docs/*.html` at the repo root — **week-1 intentions from before the
  app was built.** When they disagree with the code, the code is right.
- `brand_profiles` — the old 42-field model. The table is still on disk, unread.
  `BrandField`, `BrandBlock`, `BrandFieldLevel`, `BrandFieldSource`,
  `ProposalAction` and `BrandProfile` no longer exist.
- `context_documents` — dropped. Only `.md`/`.txt` were ever extracted while
  brandbooks arrived as PDFs, so the block that promised the model "the material
  this brand is made of" was empty for every brand that had one.
- The *"section pages are stubs"* comment in `routes/web.php` (§3 above).
- `/admin/orbe` — a design bench that goes away with the orb's own view.

**Git:** `portal/` only, 4 commits, ~10 days of work uncommitted on top. The
root folder is **not** a repo.

---

## 13. The docs, and which to read when

| file | read it when |
|---|---|
| **`CLAUDE.md`** (root) | **always, first.** The invariants and traps |
| this file | you need the map: what exists and where |
| `docs/implementaciones.md` | you need the history: what changed this cycle, why, and what is still owed. **Append to it when you ship something** |
| `docs/entregables.md` | touching the 48, the 3 steps, meetings or assets |
| `docs/asistente-admin.md` | touching anything on `/admin` that talks |
| `docs/brand-egg.md` | the five synthesised layers over the entregables — front end built, back end not |
| `docs/suscripciones.md` | billing, when it starts |

### The five ideas, if you remember nothing else

1. **The entregables are the brand.** They outrank every uploaded file: the only
   source a person reviewed one by one, and the only one that says what is *not*
   defined.
2. **Absence is stated, never omitted.** Silence gets completed by the model with
   whatever is most probable for the category. `NO DEFINIDO` does not.
3. **No provenance, because there is no path where the model wrote a value
   alone.** Every proposal is a card a person clicks.
4. **One class decides each question**; everything else is a thin reading of it.
   Say so in the docblock.
5. **Docblocks explain WHY, not what** — and usually name the alternative that
   was rejected. Read `AccessLevel::fromChecked()` for the register, then match
   it.
