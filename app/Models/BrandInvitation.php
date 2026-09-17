<?php

namespace App\Models;

use App\Enums\BrandRole;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * An invitation to a brand, waiting on the invited person's consent.
 *
 * ⚠️ ONLY EXISTS FOR ADDRESSES THAT ALREADY HAVE AN ACCOUNT. A new address has
 * nobody to ask — creating the account IS the invitation, and the setup link is
 * the consent, because only the person reading that mailbox can use it. What
 * needs asking is adding an EXISTING person to a brand they never chose, which
 * is somebody else's account and somebody else's business.
 *
 * @see database/migrations/..._create_brand_invitations_table.php
 */
class BrandInvitation extends Model
{
    /** Long enough that a working link is worth sending, short enough to rot. */
    public const LIFETIME_DAYS = 14;

    protected $fillable = [
        'client_id',
        'invited_by',
        'email',
        'role',
        'permissions',
        'token',
        'expires_at',
    ];

    protected function casts(): array
    {
        return [
            'role' => BrandRole::class,
            'permissions' => 'array',
            'expires_at' => 'datetime',
            'accepted_at' => 'datetime',
            'declined_at' => 'datetime',
        ];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function inviter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by');
    }

    public static function freshToken(): string
    {
        return Str::random(64);
    }

    /**
     * Still open: nobody has answered and it has not rotted.
     *
     * ⚠️ DERIVED, never a status column. Same rule as BrandEggState and the 48
     * entregables: a stored state is a second truth somebody has to remember to
     * move, and it can contradict the timestamps beside it.
     */
    public function isPending(): bool
    {
        return $this->accepted_at === null
            && $this->declined_at === null
            && $this->expires_at->isFuture();
    }

    /**
     * Whether this invitation is that person's to answer.
     *
     * ⚠️ ON THE ADDRESS, NOT ON A FOREIGN KEY. The row names an address because
     * resolving it to a user at invite time would mean storing the answer to
     * "does this address have an account", which is the thing being kept quiet.
     * So the check is made here, when somebody signed in opens the link.
     *
     * Case-insensitive, because addresses are and a stored invitation should
     * not fail on capitalisation somebody typed.
     */
    public function isFor(?User $user): bool
    {
        return $user !== null
            && mb_strtolower($user->email) === mb_strtolower($this->email);
    }
}
