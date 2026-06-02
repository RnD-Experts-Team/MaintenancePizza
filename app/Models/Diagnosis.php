<?php

namespace App\Models;

use Database\Factories\DiagnosisFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class Diagnosis extends Model
{
    /** @use HasFactory<DiagnosisFactory> */
    use HasFactory;

    protected $fillable = ['body', 'mistaken'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'mistaken' => 'boolean',
        ];
    }

    /** @return BelongsToMany<TicketIssue, $this> */
    public function ticketIssues(): BelongsToMany
    {
        return $this->belongsToMany(TicketIssue::class, 'diagnosis_ticket_issue')->withTimestamps();
    }

    /** @return MorphMany<Attachment, $this> */
    public function attachments(): MorphMany
    {
        return $this->morphMany(Attachment::class, 'attachable');
    }
}
