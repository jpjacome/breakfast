<?php

namespace App\Models;

use App\Enums\ClientStatus;
use App\Enums\PortalSection;
use App\Enums\ProcessStep;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class Client extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'name',
        'slug',
        'industry',
        'trademark_registered',
        'status',
        'contact_name',
        'contact_email',
        'notes',
        // What the assistant has read out of this brand's documents. Written
        // only by the onboarding read phase; see the migration for why it
        // exists at all.
        'document_digest',
        'onboarded_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => ClientStatus::class,
            'onboarded_at' => 'datetime',
            'trademark_registered' => 'boolean',
        ];
    }

    /**
     * "Marca registrada", as a sentence rather than a boolean.
     *
     * Three states, and the third is the one worth naming: null means nobody
     * has answered yet, which is different from "No". Presentation lives on the
     * model for the same reason the enums carry their own label() — so no view
     * has to keep a lookup array in step with this.
     *
     * Read by the brand page, both fichas the assistants are given, and the
     * client's own screen.
     */
    public function trademarkLabel(): string
    {
        return match ($this->trademark_registered) {
            true => 'Sí',
            false => 'No',
            default => 'Sin definir',
        };
    }

    protected static function booted(): void
    {
        static::creating(function (Client $client) {
            if (blank($client->slug)) {
                $client->slug = static::uniqueSlug($client->name);
            }
        });

        // Every brand has its entregables row from the moment it exists, so
        // nothing downstream has to handle "the row is not there yet". The
        // 48 columns are all nullable, so an empty row is a valid brand that
        // simply has not been worked on.
        static::created(function (Client $client) {
            $client->deliverables()->create([]);
        });
    }

    /**
     * Slugs are used in URLs and must stay unique across soft-deleted rows
     * too, otherwise restoring a client collides with a live one.
     */
    public static function uniqueSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'marca';
        $slug = $base;
        $n = 2;

        while (static::withTrashed()->where('slug', $slug)->exists()) {
            $slug = "{$base}-{$n}";
            $n++;
        }

        return $slug;
    }

    /**
     * The brand's own people — ACC-01.
     *
     * Was a hasMany on users.client_id, when an account belonged to exactly one
     * brand. The pivot carries what is true of each person HERE: their role in
     * this brand and the sections they may open in it. See App\Enums\BrandRole.
     */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'brand_user')
            ->withPivot(['role', 'permissions'])
            ->withTimestamps();
    }

    /**
     * The checklist items this brand has ticked off — SEG-05.
     *
     * The checklist's TEXT is an entregable; these are only which of its lines
     * are done. See App\Services\Checklist.
     */
    public function checklistTicks(): HasMany
    {
        return $this->hasMany(ChecklistTick::class);
    }

    /** The Breakfast staff put on this brand. See User::assignedClients(). */
    public function staff(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'client_staff')->withTimestamps();
    }

    /**
     * Narrow a query to the brands this Breakfast user may work on.
     *
     * The list screens call this so nobody is shown a row that opening would
     * 404 on. It is not the enforcement — EnsureStaffCoversClient is, because
     * hiding a link never stopped anyone who types the URL.
     *
     * A thin reading of User::covers(): one class decides who reaches which
     * brand, and nothing outside it touches client_staff.
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        if ($user->coversEveryBrand()) {
            return $query;
        }

        // Fails closed. A client user has no business in a brand list at all,
        // and an Equipo member sees exactly what they were assigned.
        if (! $user->isBreakfast()) {
            return $query->whereRaw('1 = 0');
        }

        return $query->whereIn(
            'clients.id',
            fn ($assigned) => $assigned
                ->select('client_id')
                ->from('client_staff')
                ->where('user_id', $user->getKey()),
        );
    }

    /**
     * The onboarding conversation, oldest first — reading order.
     *
     * ⚠️ THIS THREAD BELONGS TO THE BRAND, NOT TO A PERSON. Every Breakfast
     * user working this brand sees the same one, because it is the record of
     * how the entregables got written rather than somebody's private chat —
     * which is the opposite of assistant_messages, keyed on user_id. The author
     * is eager-loaded for exactly that reason: a shared thread has several, and
     * the screen has to say which.
     */
    public function onboardingMessages(): HasMany
    {
        return $this->hasMany(BrandOnboardingMessage::class)->with('author')->oldest('id');
    }

    /* ---------------------------------------------------------------------
       Entregables and the three steps — see docs/entregables.md
       --------------------------------------------------------------------- */

    /** The 48 entregables. One row, created with the brand. */
    public function deliverables(): HasOne
    {
        return $this->hasOne(BrandDeliverables::class);
    }

    /**
     * The entregables row, real or blank.
     *
     * booted() creates it with the brand, so in practice it is always there.
     * This exists for the rows that predate that hook and for brands built in
     * tests without going through create() — a screen must render, not blow
     * up on null.
     */
    public function deliverablesOrNew(): BrandDeliverables
    {
        return $this->deliverables ?? $this->deliverables()->make();
    }

    /** The brand's folder, newest first — how a files screen reads. */
    public function brandAssets(): HasMany
    {
        return $this->hasMany(BrandAsset::class)->latest();
    }

    /** Meetings with this brand, soonest first. */
    public function meetings(): HasMany
    {
        return $this->hasMany(Meeting::class)->orderBy('scheduled_at');
    }

    /** The next meeting, or null. What the client's dashboard card reads. */
    public function nextMeeting(): ?Meeting
    {
        return $this->meetings()->upcoming()->first();
    }

    /**
     * Who a meeting concerns: everyone in the brand who can read Reuniones.
     *
     * THE ONE PLACE THAT DECIDES IT, for the dashboard and for who gets
     * notified alike. There is no participants table on purpose — the
     * permission map already answers this question, and a second list would
     * be a second answer somebody has to keep in step.
     *
     * Mailing somebody about a meeting in a section they cannot open would be
     * telling them about a page that 404s for them.
     *
     * @return Collection<int, User>
     */
    public function meetingAudience(): Collection
    {
        return $this->users()->get()
            ->filter(fn (User $user) => $user->canRead(PortalSection::Reuniones))
            ->values();
    }

    /** Steps that have been started, oldest first — reading order. */
    public function processSteps(): HasMany
    {
        return $this->hasMany(ClientProcessStep::class)->oldest('started_at');
    }

    /** The row for one step, or null if that step was never started. */
    public function stepRecord(ProcessStep $step): ?ClientProcessStep
    {
        return $this->processSteps->firstWhere('step', $step);
    }

    /**
     * The step running right now: started and not closed.
     *
     * Derived from the rows rather than stored on the client, so it cannot go
     * stale and nothing depends on a scheduled job having run.
     */
    public function currentStep(): ?ProcessStep
    {
        return $this->processSteps
            ->first(fn (ClientProcessStep $record) => $record->isRunning())
            ?->step;
    }

    /** How many of the three are closed. The client's progress bar reads this. */
    public function completedStepCount(): int
    {
        return $this->processSteps
            ->filter(fn (ClientProcessStep $record) => $record->isComplete())
            ->count();
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    /** Two-letter monogram for the avatar tile. */
    public function initials(): string
    {
        $words = preg_split('/\s+/', trim($this->name)) ?: [];
        $letters = array_map(fn ($w) => mb_substr($w, 0, 1), array_slice($words, 0, 2));

        return mb_strtoupper(implode('', $letters)) ?: '·';
    }
}
