<?php

namespace App\Enums;

/**
 * Where one ticket issue is in the workflow (docs/MAINTENANCE.md §2):
 *
 *   new → in_review → (waiting_on_store) → (inspection) → (waiting_approval)
 *       → scheduled → work_done → closed          (or cancelled at any point)
 *
 * The bracketed steps are optional: a simple job goes straight from review to
 * scheduled. Each status names whose turn it is, which is what the dashboard
 * shows next to it.
 */
enum IssueStatus: string
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
        return match ($this) {
            self::New => 'New',
            self::InReview => 'In review',
            self::WaitingOnStore => 'Waiting on store',
            self::Inspection => 'Inspection',
            self::WaitingApproval => 'Waiting for approval',
            self::Scheduled => 'Scheduled',
            self::WorkDone => 'Work done (checking)',
            self::Closed => 'Closed',
            self::Cancelled => 'Cancelled',
        };
    }

    /**
     * Who has to act next. Null once the issue is finished.
     */
    public function whoseTurn(): ?string
    {
        return match ($this) {
            self::New, self::InReview => 'office',
            self::WaitingOnStore => 'store',
            self::Inspection, self::Scheduled => 'technician',
            self::WaitingApproval => 'approver',
            self::WorkDone => 'office / store',
            self::Closed, self::Cancelled => null,
        };
    }

    /**
     * Statuses after which nothing more happens to the issue.
     *
     * @return array<int, self>
     */
    public static function terminal(): array
    {
        return [self::Closed, self::Cancelled];
    }

    public function isTerminal(): bool
    {
        return in_array($this, self::terminal(), true);
    }

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_map(fn (self $c) => $c->value, self::cases());
    }
}
