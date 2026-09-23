<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A full JSON snapshot of a pay entry as it stood before an edit.
 *
 * Snapshots are never rewritten, so both shapes exist in history and
 * `schema_version` says which one a given row is.
 */
class DailyPayEntryRevision extends Model
{
    use HasFactory;

    /** The shape written today: {date, payments: [{..., lines: [...]}]}. Version 1 was {date, lines: [...]}. */
    public const SCHEMA_VERSION = 2;

    protected $fillable = [
        'daily_pay_entry_id',
        'snapshot',
        'schema_version',
        'edited_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'snapshot' => 'array',
            'schema_version' => 'integer',
        ];
    }

    /** @return BelongsTo<DailyPayEntry, $this> */
    public function entry(): BelongsTo
    {
        return $this->belongsTo(DailyPayEntry::class, 'daily_pay_entry_id');
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'edited_by');
    }
}
