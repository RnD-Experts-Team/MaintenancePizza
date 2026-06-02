<?php

namespace App\Models;

use App\Enums\IssueStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class IssueStatusChange extends Model
{
    use HasFactory;

    protected $fillable = ['ticket_issue_id', 'from_status', 'to_status', 'reason'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'from_status' => IssueStatus::class,
            'to_status' => IssueStatus::class,
        ];
    }

    /** @return BelongsTo<TicketIssue, $this> */
    public function ticketIssue(): BelongsTo
    {
        return $this->belongsTo(TicketIssue::class);
    }
}
