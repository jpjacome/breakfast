# Multi-marca — implementation plan

ACC-01 / ACC-02 / ACC-03 of the beta review. **One account, many brands**, with
a brand picker, the active brand visible, Brandy answering for it, and
permissions granted per brand.

> Hoy cada usuario pertenece a **una** marca, y esa relación atraviesa todo el
> portal.

---

## 1. What is actually coupled

Smaller than it looks, and the survey matters because it decides the shape:

| | |
|---|---|
| `users.client_id` | read in **21 files** |
| `$user->client` | **~15 portal read sites**, all of them "the brand I am looking at" |
| `users.permissions` | read **only inside `User`** — one class already decides |
| `users.role` | client-vs-staff **and** owner-vs-member, in one column |

The permission map being sealed inside `User` is what makes this tractable:
`accessTo()` is the one method that answers everything, and the middleware, the
gates, the sidebar and the grid are all thin readings of it. **Change what
`accessTo()` reads and the whole portal follows.**

### ⚠️ The finding that makes this safe

**`users.email` is unique.** So a person with two brands *cannot* exist today —
they would need two addresses. There is no duplicate-email data to merge, and
**every existing user maps to exactly one pivot row**. The migration is
mechanical, not a reconciliation.

(Merging two accounts that belong to the same human is a separate, manual job
for later, and it is not on the critical path.)

---

## 2. Storage

### `users.role` holds ONE question, not two

Today it holds `admin` · `equipo` · `cliente_owner` · `cliente_miembro` — which
is *which side of the app you are on* **and** *what you are inside your brand*,
in one column. With many brands those come apart: a person can be the owner of
one brand and a member of another. **A single column cannot say that**, and
keeping it would be exactly the second truth that can contradict the first
(CLAUDE.md §8 rule 2).

So:

```php
enum UserRole: string     // WHICH SIDE. Stays on users.role
{
    case Admin;           // Breakfast
    case Equipo;          // Breakfast
    case Cliente;         // ← replaces ClienteOwner + ClienteMiembro
}

enum BrandRole: string    // WHAT YOU ARE IN ONE BRAND. Lives on the pivot
{
    case Owner;
    case Miembro;
}
```

### The pivot

```php
Schema::create('brand_user', function (Blueprint $table) {
    $table->id();
    $table->foreignId('client_id')->constrained()->cascadeOnDelete();
    $table->foreignId('user_id')->constrained()->cascadeOnDelete();
    $table->string('role')->default('miembro');   // BrandRole
    $table->json('permissions')->nullable();      // the same map, per brand
    $table->timestamps();

    $table->unique(['client_id', 'user_id']);
});
```

⚠️ **`permissions` moves onto the pivot, whole.** The map's shape does not
change — `{section_value: access_level}`, no "none" level, Read or absent. What
changes is that there is one per brand, because "puede ver Reuniones" is a
question about a person *in a brand*, not about a person.

### The data migration, in one pass

For every user with a `client_id`: insert one pivot row carrying their current
`permissions` and `cliente_owner → owner` / `cliente_miembro → miembro`, then
rewrite `users.role` to `cliente`.

⚠️ **`users.client_id` and `users.permissions` are NOT dropped in this
migration.** Two reasons, and the second is the real one:

1. Production migrations run by hand in a cPanel terminal (CLAUDE.md §3), with
   no staging rehearsal and no rollback but the `down()` I wrote.
2. **Leaving them makes the migration reversible without data loss.** Drop them
   in a second migration, a week later, once the live app has been running on
   the pivot.

Until that second migration they are **dead columns nobody reads** — and a test
pins that, because a dead column that something still reads is worse than one
that was dropped.

---

## 3. The active brand

### One class decides

```php
App\Services\ActiveBrand
    public function for(User $user): ?Client   // memoised per request
    public function set(User $user, Client $client): void
    public function options(User $user): Collection
```

Resolution order, and each step is a fail-closed narrowing:

1. the `client_id` in the session, **if it is still one of the user's brands**;
2. otherwise their first brand by name;
3. otherwise `null` — which is what Breakfast staff get, and what a client user
   with no brand has always got.

⚠️ **Step 1 re-checks membership on every request.** A session outlives a
permission change: if the owner of brand B removes somebody, the stale
`active_client_id` in their session must not keep answering. Re-reading the
relation is the whole guard.

### ⚠️ The archiving invariant must survive

Today `accessTo()` returns null when `$this->client === null`, and `client()`
excludes soft-deleted rows — **so archiving a brand closes its portal, and
restoring gives everyone back exactly what they had, because nothing was taken
away.** That mechanism is load-bearing and invisible; it is easy to delete by
accident here.

It survives by putting the same exclusion on the new relation: the `brands()`
relation ignores trashed rows, so an archived brand **drops out of the picker
and out of `activeClient()`**, and a user whose only brand is archived gets
`null` exactly as today. A user with two brands keeps the other one.

---

## 4. What changes in `User`

```php
public function brands(): BelongsToMany      // client_id + role + permissions
public function activeBrand(): ?Client       // through ActiveBrand
public function brandRole(?Client = null): ?BrandRole
public function isBrandOwner(?Client = null): bool
```

**`accessTo(PortalSection $section, ?Client $client = null)`** — the signature
gains an optional brand and **keeps working unchanged everywhere it is called
today**, because the default is the active brand. That is what keeps the
middleware, the two gates, `canRead`/`canWrite`, `visibleSections()` and the
permission grid from needing to know any of this happened.

⚠️ **`canReachBrandAsset()` is the one caller that must pass the brand
explicitly** — `accessTo(BrandAssets, $asset->client)`. It answers about the
file's own brand, not about whatever brand the tab happens to be showing. Using
the active brand there would make a download succeed or 404 depending on a
dropdown, which is the kind of bug that looks like flakiness for a week.

⚠️ **`grantCeiling()` keeps capping at Read** and stays per brand. An owner of
brand A may not grant anything in brand B: `EnforceBrandPermissionCeiling` now
re-clamps **one brand's** rows rather than the user's single map.

---

## 5. ⚠️ The assistant thread must be keyed per brand

`assistant_messages` is keyed on `user_id` + `surface` and replays the last
turns into the prompt. With one account on two brands **that thread would carry
brand A's turns into brand B's context** — a cross-brand leak inside a single
user's own history, which is the one thing the client's assistant is forbidden
to do (CLAUDE.md §7).

The column already exists and is nullable. `AssistantMessage::scopeThread()`
gains the brand, and the portal surface always passes it.

Everything about the prompt cache is unaffected: block 2 is per brand and is
built from the active brand, so the prefix is as stable as it was — it is simply
selected by a different route.

---

## 6. Screens

- **The picker** goes in the portal sidebar, where the brand name is printed
  today (`components/layouts/portal.blade.php`). One brand = the name, no
  control. Two or more = a picker. **Breakfast staff still see "Breakfast" and
  no picker** — they carry no brands, and a portal page that dereferences the
  active brand flatly is a 500 for them (CLAUDE.md §11).
- **`POST /portal/marca`** switches, then `back()`.
- **`/admin/clientes/{marca}` and `/portal/equipo`** both render the permission
  grid, which now writes a pivot row instead of a user column. The grid itself
  does not change — it already draws from `grantableSections()`.
- **Inviting someone who already has an account** attaches a brand instead of
  creating a user. `InviteUserToClient` is where that decision belongs, since it
  already serves both doors.

---

## 7. Build order

| | | |
|---|---|---|
| 1 | `BrandRole`, `UserRole::Cliente`, the pivot + data migration | — |
| 2 | `User`: `brands()`, `accessTo($section, ?$client)`, the role helpers | 1 |
| 3 | `ActiveBrand` + session resolution | 2 |
| 4 | The ~15 `$user->client` read sites → `activeBrand()` | 3 |
| 5 | `AssistantMessage::scopeThread()` + the portal assistant | 3 |
| 6 | The picker, `POST /portal/marca` | 3 |
| 7 | `InviteUserToClient` attaches rather than duplicates | 2 |
| 8 | `EnforceBrandPermissionCeiling` per brand | 2 |
| 9 | Tests | all |
| 10 | *(a week later)* drop `users.client_id` and `users.permissions` | live |

Steps 1–3 are the risky ones and everything after them is mechanical.

---

## 8. Tests — `tests/Feature/MultiBrandTest.php`

- a user on two brands reaches both, and the picker lists exactly those two
- **switching brands changes what Brandy answers about**, and the thread of one
  brand never appears in the other
- a stale `active_client_id` for a brand the user was **removed from** falls
  back rather than answering
- **archiving** a brand drops it from the picker; a user whose only brand is
  archived is closed out exactly as today; restoring gives it back untouched
- owner of A + member of B: **`/portal/equipo` is reachable on A and 404s on B**
- `canReachBrandAsset()` answers about the **asset's** brand, not the active one
- permissions granted in A do not leak to B
- `PortalPermissionsTest` and `AdminClientUserTest` still pass
- **nothing reads `users.client_id` or `users.permissions`** any more

---

## 10. Status — built 2026-09-14, closed 2026-09-15

**Steps 1-9 were done on 2026-09-14. 518/518 tests passed, `pint` clean, and
the migration was run against the local database** (3 client users -> 3
memberships, roles and permission maps carried over intact).

**Step 10 closed on 2026-09-15**, once the pivot had been live in production for
a full deploy cycle. 588/588. It took two commits on purpose — the factory first,
the columns second — because the factory was the last thing writing them, as
shorthand that let ~85 test sites declare a membership without saying so. One
commit would have meant a failure that could have come from either half.

| | | |
|---|---|---|
| 1 | `BrandRole`, `brand_user`, the backfill | done |
| 2 | `User::brands()`, `accessTo($section, ?$client)`, `brandRole()`, `permissionsIn()` | done |
| 3 | `ActiveBrand` + session resolution | done |
| 4 | Every `$user->client` read site -> `activeBrand()` | done |
| 5 | `AssistantMessage::scopeThread()` keyed per brand | done |
| 6 | The picker + `POST /portal/marca/{client}` | done |
| 7 | `InviteUserToClient::attach()` — an existing account is added, not duplicated | done |
| 8 | `EnforceBrandPermissionCeiling` per brand | done |
| 9 | `tests/Feature/MultiBrandTest.php` — 21 tests | done |
| 10 | Drop `users.client_id` and `users.permissions` | **done 2026-09-15** |

### Verified in the browser

Signed in as an account owning one brand and merely a member of another. On
switching: Brandy's brand changed, the sidebar collapsed to the sections held
in the new brand, and the role label went from "Dueño de marca" to "Miembro".
Per-brand permissions, end to end.

### Two decisions made while building

- **`users.role` was NOT collapsed to a single `Cliente` case.** The plan in §2
  proposed it, and it is still the tidier end state — but the cases are named in
  ~25 test sites and in the factory, and rewriting those in the same pass as the
  storage change would have meant one commit where a failure could have come
  from either half. `users.role` is now only read for `isBreakfast()` and as the
  factory's shorthand; `BrandRole` on the pivot is what decides anything about a
  brand. ⚠️ **It did NOT go with step 10, and it is the last piece of §2 still
  open.** Step 10 was about storage; this is about vocabulary, and the same
  argument for splitting them applies again.
- **Removing somebody detaches the membership** and deletes the account only
  when it was their last brand. Deleting the row outright would have let the
  owner of one brand close somebody out of another.

### ⚠️ Deploying this

One migration, run by hand in the cPanel terminal, and it **backfills in the
same run** — so the window between "table exists" and "memberships exist" is
inside one command rather than between two. Upload the PHP and `public/build`
FIRST, then migrate: the new code reads `brand_user` and the old code does not
write it, so the other order leaves a gap where a client user has no brand.

No new env keys, so `portal/tests/.env` is untouched.

---

## 9. Open for Breakfast

1. **One invitation mail per brand, or one per person?** The review asked for
   one mail when a person has several brands. That is possible only once this
   lands — and it is a change to `NotifyAboutMeeting`, not to this plan. Suggest
   shipping per-brand mails first and grouping as a follow-up.
2. ~~**Who may attach an existing account to a brand?**~~ **Built as
   Breakfast-only.** `/admin` adds an existing address to the brand — no second
   account, no setup mail, no touching the password they already have. A brand
   owner inviting a known address is still refused, with *"Ya existe una cuenta
   con ese correo. Pedile al equipo de Breakfast que la agregue a tu marca"* —
   attaching there would tell an owner that an account exists on an address they
   only guessed at. Say if you would rather owners could do it too.
