<?php

namespace App\Models;

use App\Models\Concerns\HasNotesAndAttachments;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * "We need a photo of the label" -- a question the office asks the store
 * during review, and the store's answer. Answered by the store in the
 * dashboard, or by the specialist on their behalf after a phone call
 * (`answered_by_name`: who answered, typed, optional). Files sent with either side are its
 * attachments.
 */
class IssueInfoRequest extends Model
{
    use HasNotesAndAttachments;

    protected $fillable = [
        'ticket_issue_id',
        'question',
        'asked_by',
        'answer',
        'answered_by_name',
        'answered_by',
        'answered_at',
        'mistaken',
    ];

    protected $touches = ['ticketIssue'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'answered_at' => 'datetime',
            'mistaken' => 'boolean',
        ];
    }

    /** @return BelongsTo<TicketIssue, $this> */
    public function ticketIssue(): BelongsTo
    {
        return $this->belongsTo(TicketIssue::class);
    }

    /** @return BelongsTo<User, $this> */
    public function asker(): BelongsTo
    {
        return $this->belongsTo(User::class, 'asked_by');
    }

    /** @return BelongsTo<User, $this> */
    public function answerer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'answered_by');
    }

    public function isAnswered(): bool
    {
        return $this->answered_at !== null;
    }
}
