<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One ticked item of a brand's implementation checklist — SEG-05.
 *
 * A row means done; no row means not done. There is no boolean and no
 * "unticked" row: unticking DELETES. Same reasoning as meeting_reminders and
 * users.permissions — absence is a state that cannot contradict anything.
 *
 * ⚠️ WRITTEN BY THE CLIENT, which makes it unusual in this app. It is the only
 * thing on the client side of the portal, besides their own profile, that a
 * client can change — and the narrowness is the point: they can say an item is
 * done, and nothing else. The text of the item belongs to Breakfast and lives
 * in the entregable.
 *
 * No timestamps(): checked_at is the only time this row has, and created_at
 * would be the same instant wearing a second name.
 */
class ChecklistTick extends Model
{
    public $timestamps = false;

    protected $fillable = ['client_id', 'item_key', 'checked_by', 'checked_at'];

    protected function casts(): array
    {
        return ['checked_at' => 'datetime'];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    /** Who ticked it. Null when that person has since been removed. */
    public function checkedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'checked_by');
    }
}
