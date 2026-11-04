<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A change made to a trip from the dashboard rather than by the technician
 * himself: what it touched (`subject_type` trip|stop|session|event, plus id),
 * the values before and after, why, and who. Append-only.
 */
class TripAudit extends Model
{
    protected $fillable = ['trip_id', 'subject_type', 'subject_id', 'action', 'before', 'after', 'reason', 'created_by'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'before' => 'array',
            'after' => 'array',
        ];
    }

    /** @return BelongsTo<Trip, $this> */
    public function trip(): BelongsTo
    {
        return $this->belongsTo(Trip::class);
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
