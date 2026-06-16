<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;

class DailyPayEntry extends Model
{
    use HasFactory;

    protected $fillable = ['date'];

    protected function casts(): array
    {
        return [
            'date' => 'date',
        ];
    }

    /** @return HasMany<DailyPayLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(DailyPayLine::class);
    }

    /** @return HasMany<DailyPayEntryRevision, $this> */
    public function revisions(): HasMany
    {
        return $this->hasMany(DailyPayEntryRevision::class)->latest();
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return HasManyThrough<TicketIssue, DailyPayLine, $this> */
    public function ticketIssues(): HasManyThrough
    {
        return $this->hasManyThrough(TicketIssue::class, DailyPayLine::class);
    }
}
