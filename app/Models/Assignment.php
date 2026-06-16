<?php

namespace App\Models;

use App\Models\Concerns\HasNotesAndAttachments;
use Database\Factories\AssignmentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
class Assignment extends Model
{
    /** @use HasFactory<AssignmentFactory> */
    use HasFactory, HasNotesAndAttachments;

    protected $fillable = ['assigned_date', 'assigned_hour', 'mistaken'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'assigned_date' => 'date',
            'mistaken' => 'boolean',
        ];
    }

    /** @return HasMany<AssignmentDelay, $this> */
    public function delays(): HasMany
    {
        return $this->hasMany(AssignmentDelay::class);
    }

    /** @return BelongsToMany<TicketIssue, $this> */
    public function ticketIssues(): BelongsToMany
    {
        return $this->belongsToMany(TicketIssue::class, 'assignment_ticket_issue')->withTimestamps();
    }
    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
