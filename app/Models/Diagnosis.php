<?php

namespace App\Models;

use App\Models\Concerns\HasNotesAndAttachments;
use Database\Factories\DiagnosisFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
class Diagnosis extends Model
{
    /** @use HasFactory<DiagnosisFactory> */
    use HasFactory, HasNotesAndAttachments;

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

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
