<?php

namespace App\Models;

use App\Enums\AccessLevel;
use App\Enums\BrandRole;
use App\Enums\PortalSection;
use App\Enums\UserRole;
use App\Notifications\ResetPasswordLink;
use App\Services\ActiveBrand;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Fortify\TwoFactorAuthenticatable;

#[Fillable(['name', 'email', 'password', 'role', 'client_id', 'permissions'])]
#[Hidden(['password', 'remember_token', 'two_factor_secret', 'two_factor_recovery_codes'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable, TwoFactorAuthenticatable;

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'role' => UserRole::class,
            'permissions' => 'array',
        ];
    }

    /**
     * The brand this user belongs to. Null for Breakfast staff.
     */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    /**
     * For Breakfast staff: the brands they were put on. Empty for client users,
     * who reach theirs through brands() below instead.
     *
     * Not the same question as brands(). This is which brands a staff member
     * SUPPORTS; that is which brands a client user BELONGS TO. Two relations
     * because they are granted by different people for different reasons, and
     * a single one would have to be filtered by role at every call site.
     */
    public function assignedClients(): BelongsToMany
    {
        return $this->belongsToMany(Client::class, 'client_staff')->withTimestamps();
    }

    /**
     * For client users: every brand this account belongs to — ACC-01.
     *
     * Replaces the single client_id. The pivot carries what is true of this
     * person IN each brand: their role there and the sections they may open
     * there. See App\Enums\BrandRole and the brand_user migration.
     *
     * ⚠️ TRASHED BRANDS ARE EXCLUDED, exactly as client() excluded them, and
     * this is load-bearing rather than tidy. Archiving a brand is how its
     * portal gets closed: the brand drops out of here, so it leaves the picker
     * and stops being resolvable as the active brand — while no user row is
     * touched, which is why restoring gives everybody back precisely what they
     * had. Add withTrashed() here and archiving silently stops working.
     */
    public function brands(): BelongsToMany
    {
        return $this->belongsToMany(Client::class, 'brand_user')
            ->withPivot(['role', 'permissions'])
            ->withTimestamps();
    }

    /* ---------------------------------------------------------------------
     | Role helpers
     |
     | Every authorization decision should go through one of these rather
     | than comparing the raw string, so swapping the permission backend
     | later stays a contained change.
     --------------------------------------------------------------------- */

    /** Works at Breakfast (admin or equipo). */
    public function isBreakfast(): bool
    {
        return $this->role->isBreakfast();
    }

    public function isAdmin(): bool
    {
        return $this->role === UserRole::Admin;
    }

    /** Belongs to a client brand rather than to Breakfast. */
    public function isClient(): bool
    {
        return ! $this->isBreakfast();
    }

    /* ---------------------------------------------------------------------
     | Which brand, and what am I in it
     |
     | Before multi-marca both questions had one answer each, so they were
     | columns. Now the first is a choice (App\Services\ActiveBrand decides it)
     | and the second is per brand (the brand_user pivot holds it).
     --------------------------------------------------------------------- */

    /**
     * The brand this request is about, or null.
     *
     * A thin reading of ActiveBrand, which is the one place that decides. It
     * sits here so that call sites keep asking the user — $user->activeBrand()
     * reads the way $user->client did — without every one of them having to
     * resolve the service itself.
     */
    public function activeBrand(): ?Client
    {
        return app(ActiveBrand::class)->for($this);
    }

    /**
     * What this person is inside a brand — theirs by membership, not by
     * account. Null when they are not in that brand at all.
     *
     * Defaults to the active brand, so the common call reads as a question
     * about "here".
     */
    public function brandRole(?Client $client = null): ?BrandRole
    {
        $client ??= $this->activeBrand();

        if ($client === null || $this->isBreakfast()) {
            return null;
        }

        // relationLoaded() keeps a team screen from firing one query per row.
        $membership = $this->relationLoaded('brands')
            ? $this->brands->firstWhere('id', $client->id)
            : $this->brands()->whereKey($client->getKey())->first();

        return $membership === null
            ? null
            : BrandRole::tryFrom((string) $membership->pivot->role);
    }

    /**
     * Owns the brand: builds its team, and will see its billing.
     *
     * ⚠️ Per brand, not per account. The same person can own one brand and be
     * a member of another, which is precisely why the role left users.role —
     * see App\Enums\BrandRole.
     */
    public function isBrandOwner(?Client $client = null): bool
    {
        return $this->brandRole($client)?->owns() ?? false;
    }

    /**
     * The permissions map for one brand: {section_value: access_level}.
     *
     * ⚠️ Nothing outside this class reads it, here or anywhere — the same rule
     * that governed users.permissions, carried over to the pivot.
     */
    private function permissionsIn(Client $client): array
    {
        $membership = $this->relationLoaded('brands')
            ? $this->brands->firstWhere('id', $client->id)
            : $this->brands()->whereKey($client->getKey())->first();

        if ($membership === null) {
            return [];
        }

        $stored = $membership->pivot->permissions;

        // The pivot is not a model with casts, so the JSON arrives as a string.
        if (is_string($stored)) {
            $stored = json_decode($stored, true);
        }

        return is_array($stored) ? $stored : [];
    }

    /* ---------------------------------------------------------------------
     | Brand scope — which brands a Breakfast user may work on
     |
     | One question, two methods, and nothing outside this class should look
     | at the client_staff table directly. EnsureStaffCoversClient and
     | Client::scopeVisibleTo() are both thin readings of covers().
     --------------------------------------------------------------------- */

    /**
     * Reaches every brand without being assigned to any.
     *
     * True for Admins: the role is the whole back office, and an Admin who
     * found a brand missing could put themselves on it in two clicks anyway,
     * so pretending otherwise would be ceremony rather than a control.
     */
    public function coversEveryBrand(): bool
    {
        return $this->isAdmin();
    }

    /** May this Breakfast user work on this brand? */
    public function covers(Client $client): bool
    {
        if (! $this->isBreakfast()) {
            return false;
        }

        if ($this->coversEveryBrand()) {
            return true;
        }

        // relationLoaded() keeps a list screen from firing one query per row;
        // contains() on the loaded collection answers the same question.
        return $this->relationLoaded('assignedClients')
            ? $this->assignedClients->contains($client)
            : $this->assignedClients()->whereKey($client->getKey())->exists();
    }

    /**
     * May this person download this file?
     *
     * THE ONE PLACE THAT DECIDES IT. One route serves both sides — the link
     * pasted into an entregable has to work for the Breakfast team writing it
     * and for the client reading it — so the two answers live together rather
     * than in two controllers that could drift apart.
     *
     * Fails closed: a client user whose client_id does not match, or who was
     * never granted Brand assets, gets nothing. A Breakfast user has to cover
     * the brand, exactly as EnsureStaffCoversClient would have decided.
     *
     * ⚠️ AND AN INTERNAL FILE IS BREAKFAST'S ALONE. A brand's folder holds the
     * contract, the pricing sheet and the team's working notes as well as the
     * deliverables, so the visibility is checked here rather than in the screen
     * that lists them — the link is public knowledge the moment it is pasted
     * into an entregable, so a check that only ran on the listing page would be
     * no check at all.
     */
    public function canReachBrandAsset(BrandAsset $asset): bool
    {
        // ⚠️ A file with no brand yet — attached to Brandy before anyone said
        // which brand it was about. It belongs to Breakfast until somebody
        // files it, and covers() has no brand to be asked about, so decide it
        // here rather than letting a null slip into the question.
        if ($asset->client === null) {
            return $this->isBreakfast();
        }

        if ($this->isBreakfast()) {
            return $this->covers($asset->client);
        }

        if ($asset->isInternal()) {
            return false;
        }

        // ⚠️ THE ASSET'S BRAND, NOT THE ACTIVE ONE. With one account on several
        // brands the tab might be showing a different brand than the file
        // belongs to, and a download that succeeded or 404'd depending on a
        // dropdown would read as flakiness rather than as a rule.
        return $this->canRead(PortalSection::BrandAssets, $asset->client);
    }

    /* ---------------------------------------------------------------------
     | Portal permissions
     |
     | One method decides everything — accessTo() — and canRead/canWrite are
     | thin readings of it. Nothing outside this class should look at the
     | permissions array directly.
     --------------------------------------------------------------------- */

    /**
     * This user's level in a section, or null if it is closed to them.
     *
     * Brand owners read the same map members do. Their access is granted by
     * Breakfast when they are invited, not implied by the role. What is granted
     * is only ever which sections they may open — every one of the nine is
     * read-only for a client, so the map holds Read or the section is absent.
     * See grantCeiling().
     *
     * Two things do NOT come from the map, and both are what being the owner
     * means rather than something to hand out: Equipo, or they could never
     * build a team, and Suscripción, because it is their invoice.
     */
    public function accessTo(PortalSection $section, ?Client $client = null): ?AccessLevel
    {
        // Breakfast staff support every brand, so nothing in the portal is
        // closed to them. They normally live in /admin.
        if ($this->isBreakfast()) {
            return AccessLevel::Write;
        }

        // Defaults to "the brand I am looking at" so every existing caller —
        // the middleware, the two gates, canRead/canWrite, the sidebar, the
        // permission grid — keeps working untouched. Pass a brand explicitly
        // only when the question is about a specific one rather than about
        // here; canReachBrandAsset() is the one place that must.
        $client ??= $this->activeBrand();

        // A client user with no brand is a broken record (see the docblock on
        // UserRole). Fail closed rather than guess which brand they meant.
        //
        // ⚠️ An ARCHIVED brand lands here too, and that is the whole archiving
        // mechanism: brands() excludes trashed rows, so the brand cannot be
        // resolved as active and cannot be passed in from anywhere that found
        // it through the relation. Archiving changes no user row, so restoring
        // gives everyone back exactly what they had — nothing was taken away.
        if ($client === null) {
            return null;
        }

        if ($section->isAlwaysOn()) {
            return $section->baselineLevel();
        }

        if ($section->isOwnerOnly()) {
            return $this->isBrandOwner($client) ? AccessLevel::Write : null;
        }

        // Present or absent is the whole question here: a grantable section is
        // read-only for a client, so a stored 'write' — a row from when the
        // grid still offered Editar — reads back as Read rather than being
        // honoured. Clamped on the way out as well as on the way in, so an old
        // row is inert immediately instead of waiting for the next write to
        // EnforceBrandPermissionCeiling. See grantCeiling().
        $stored = $this->permissionsIn($client)[$section->value] ?? null;

        return $stored === null || AccessLevel::tryFrom($stored) === null
            ? null
            : AccessLevel::Read;
    }

    /**
     * The most this user may hand to someone else in a section, or null if
     * they cannot pass it on at all.
     *
     * Privileges only ever narrow going down the chain: Breakfast grants the
     * brand owner, the owner grants their team, and nobody can give what they
     * were not given. Equipo and Suscripción return null because they are the
     * owner's by role and are not delegable at all.
     *
     * ⚠️ A grantable section tops out at Read, for everyone, including
     * Breakfast. The client side of the portal has no write path at all —
     * Breakfast writes a brand, the brand reads it — so Editar on one of these
     * nine was a promise no screen could keep. Granting it made a checkbox
     * true and changed nothing else.
     *
     * That cap is here rather than in the form because this is the one method
     * that answers "what may be passed on": the grid draws itself from it and
     * ValidatesSectionPermissions clamps against it, so neither can drift.
     * Write survives in AccessLevel for the two places it is still real —
     * Perfil, which everyone edits, and Breakfast staff in accessTo().
     */
    public function grantCeiling(PortalSection $section, ?Client $client = null): ?AccessLevel
    {
        if (! $section->isGrantable()) {
            return null;
        }

        // Per brand: owning one brand grants nothing in another.
        return $this->accessTo($section, $client) === null ? null : AccessLevel::Read;
    }

    /**
     * Sections this user is able to hand out, so a grid never offers a row
     * that would be rejected or silently clamped to nothing.
     *
     * @return array<int, PortalSection>
     */
    public function grantableSections(?Client $client = null): array
    {
        return array_values(array_filter(
            PortalSection::grantable(),
            fn (PortalSection $section) => $this->grantCeiling($section, $client) !== null,
        ));
    }

    public function canRead(PortalSection $section, ?Client $client = null): bool
    {
        return $this->accessTo($section, $client)?->covers(AccessLevel::Read) ?? false;
    }

    public function canWrite(PortalSection $section, ?Client $client = null): bool
    {
        return $this->accessTo($section, $client)?->covers(AccessLevel::Write) ?? false;
    }

    /** @return array<int, PortalSection> Sections to show this user in the nav. */
    public function visibleSections(?Client $client = null): array
    {
        return array_values(array_filter(
            PortalSection::cases(),
            fn (PortalSection $section) => $this->canRead($section, $client),
        ));
    }

    /** Where this user lands after signing in. */
    public function homeRoute(): string
    {
        return $this->isBreakfast()
            ? route('admin.home')
            : route('portal.home');
    }

    /**
     * Laravel's own reset mail is English and signs off as a generic app, and
     * this one is read by Spanish-speaking clients — so send ours instead.
     */
    public function sendPasswordResetNotification(#[\SensitiveParameter] $token): void
    {
        $this->notify(new ResetPasswordLink($token));
    }

    public function initials(): string
    {
        $words = preg_split('/\s+/', trim($this->name)) ?: [];
        $letters = array_map(fn ($w) => mb_substr($w, 0, 1), array_slice($words, 0, 2));

        return mb_strtoupper(implode('', $letters)) ?: '·';
    }

    /**
     * What the assistant calls you.
     *
     * The first word of the name, because "Hola, María" is how a person greets
     * another and "Hola, María Fernanda Salazar Vega" is how a form does. Falls
     * back to the whole name when there is only one word, and to null when
     * there is nothing usable — the caller drops the line entirely rather than
     * greeting an empty string.
     */
    public function firstName(): ?string
    {
        $first = preg_split('/\s+/', trim($this->name))[0] ?? '';

        return $first === '' ? null : $first;
    }
}
