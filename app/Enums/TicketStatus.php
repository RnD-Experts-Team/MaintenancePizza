<?php

namespace App\Enums;

/**
 * A ticket's status. Never stored: it is DERIVED from its issues by
 * TicketStatusService (and its SQL twin in TicketService), so it mirrors
 * IssueStatus value for value.
 */
enum TicketStatus: string
{
    case New = 'new';
    case InReview = 'in_review';
    case WaitingOnStore = 'waiting_on_store';
    case Inspection = 'inspection';
    case WaitingApproval = 'waiting_approval';
    case Scheduled = 'scheduled';
    case WorkDone = 'work_done';
    case Closed = 'closed';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return IssueStatus::from($this->value)->label();
    }

    /**
     * @return array<int, self>
     */
    public static function terminal(): array
    {
        return [self::Closed, self::Cancelled];
    }

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_map(fn (self $c) => $c->value, self::cases());
    }
}
