<?php

namespace App\Models;

use App\Enums\TripEventKind;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One thing that happened inside a session: a break, travel or parts run
 * starting or ending. A mistaken event is kept for the record and ignored by
 * every duration.
 */
class TripEvent extends Model
{
    protected $fillable = ['trip_session_id', 'kind', 'at', 'mistaken', 'created_by'];

    /** Adding a break changes the session's paid time. */
    protected $touches = ['session'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'kind' => TripEventKind::class,
            'at' => 'datetime',
            'mistaken' => 'boolean',
        ];
    }

    /** @return BelongsTo<TripSession, $this> */
    public function session(): BelongsTo
    {
        return $this->belongsTo(TripSession::class, 'trip_session_id');
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
