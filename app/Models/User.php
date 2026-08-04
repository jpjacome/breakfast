<?php

namespace App\Models;

use App\Enums\UserRole;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Fortify\TwoFactorAuthenticatable;

#[Fillable(['name', 'email', 'password', 'role', 'client_id'])]
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
        ];
    }

    /**
     * The brand this user belongs to. Null for Breakfast staff.
     */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
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

    /** Where this user lands after signing in. */
    public function homeRoute(): string
    {
        return $this->isBreakfast()
            ? route('admin.home')
            : route('portal.home');
    }

    public function initials(): string
    {
        $words = preg_split('/\s+/', trim($this->name)) ?: [];
        $letters = array_map(fn ($w) => mb_substr($w, 0, 1), array_slice($words, 0, 2));

        return mb_strtoupper(implode('', $letters)) ?: '·';
    }
}
