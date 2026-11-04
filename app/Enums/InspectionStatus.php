<?php

namespace App\Enums;

/**
 * One inspection of an issue: booked, done (findings recorded), or called off.
 */
enum InspectionStatus: string
{
    case Scheduled = 'scheduled';
    case Done = 'done';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Scheduled => 'Scheduled',
            self::Done => 'Done',
            self::Cancelled => 'Cancelled',
        };
    }

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_map(fn (self $c) => $c->value, self::cases());
    }
}
