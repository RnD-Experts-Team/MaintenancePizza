<?php

namespace App\Models;

use Database\Factories\PartUsageFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class PartUsage extends Model
{
    /** @use HasFactory<PartUsageFactory> */
    use HasFactory;

    protected $fillable = ['part_id', 'cost', 'mistaken'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'cost' => 'decimal:2',
            'mistaken' => 'boolean',
        ];
    }

    /** @return BelongsTo<Part, $this> */
    public function part(): BelongsTo
    {
        return $this->belongsTo(Part::class);
    }

    /** @return BelongsToMany<TicketIssue, $this> */
    public function ticketIssues(): BelongsToMany
    {
        return $this->belongsToMany(TicketIssue::class, 'part_ticket_issue')->withTimestamps();
    }

    /** @return MorphMany<Attachment, $this> */
    public function attachments(): MorphMany
    {
        return $this->morphMany(Attachment::class, 'attachable');
    }
}
