<?php

namespace App\Enums;

enum IssueStatus: string
{
    case Pending = 'pending';
    case Assigned = 'assigned';
    case InProgress = 'in_progress';
    case Complete = 'complete';
    case Deferred = 'deferred';
    case Cancelled = 'cancelled';

    /**
     * Human-readable label for the status.
     */
    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::Assigned => 'Assigned',
            self::InProgress => 'In Progress',
            self::Complete => 'Complete',
            self::Deferred => 'Deferred',
            self::Cancelled => 'Cancelled',
        };
    }

    /**
     * Statuses that count an issue as "finished" for ticket-completion rollup.
     *
     * @return array<int, self>
     */
    public static function terminal(): array
    {
        return [self::Complete, self::Deferred, self::Cancelled];
    }

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_map(fn (self $c) => $c->value, self::cases());
    }
}
