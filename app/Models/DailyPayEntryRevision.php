<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DailyPayEntryRevision extends Model
{
    use HasFactory;

    protected $fillable = [
        'daily_pay_entry_id',
        'snapshot',
        'edited_by',
    ];

    protected function casts(): array
    {
        return [
            'snapshot' => 'array',
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
