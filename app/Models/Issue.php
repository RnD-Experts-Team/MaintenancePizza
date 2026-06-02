<?php

namespace App\Models;

use Database\Factories\IssueFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Issue extends Model
{
    /** @use HasFactory<IssueFactory> */
    use HasFactory, SoftDeletes;

    protected $fillable = ['title', 'description'];

    /** @return HasMany<TicketIssue, $this> */
    public function ticketIssues(): HasMany
    {
        return $this->hasMany(TicketIssue::class);
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
