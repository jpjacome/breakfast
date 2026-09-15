<?php

namespace App\Models;

use App\Enums\ProcessStep;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One step of the three, for one brand, with when it started and finished.
 *
 * A row only exists once the step has been started. "Not started" has no
 * representation because it does not need one — the absence is it.
 */
class ClientProcessStep extends Model
{
    protected $fillable = ['step', 'started_at', 'completed_at', 'completed_by'];

    protected function casts(): array
    {
        return [
            'step' => ProcessStep::class,
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function completedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'completed_by');
    }

    public function isComplete(): bool
    {
        return $this->completed_at !== null;
    }

    /** Started and not yet closed — the one the client sees as "en curso". */
    public function isRunning(): bool
    {
        return $this->started_at !== null && $this->completed_at === null;
    }
}
