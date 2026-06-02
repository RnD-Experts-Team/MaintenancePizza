<?php

namespace App\Enums;

enum Priority: string
{
    case Urgent = 'urgent';
    case High = 'high';
    case Medium = 'medium';
    case Low = 'low';

    /**
     * Human-readable label for the priority.
     */
    public function label(): string
    {
        return match ($this) {
            self::Urgent => 'Urgent',
            self::High => 'High',
            self::Medium => 'Medium',
            self::Low => 'Low',
        };
    }

    /**
     * Relative weight (higher = more urgent) for sorting.
     */
    public function weight(): int
    {
        return match ($this) {
            self::Urgent => 4,
            self::High => 3,
            self::Medium => 2,
            self::Low => 1,
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
