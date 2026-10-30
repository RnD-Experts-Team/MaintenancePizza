<?php

namespace App\Enums;

/**
 * How badly the problem affects the store, as the store said when reporting
 * it. Read alongside priority when deciding what goes first.
 */
enum Impact: string
{
    case None = 'none';
    case Slowed = 'slowed';
    case PartlyDown = 'partly_down';
    case Closed = 'closed';

    public function label(): string
    {
        return match ($this) {
            self::None => 'No effect on service',
            self::Slowed => 'Slowing us down',
            self::PartlyDown => 'Part of the store is down',
            self::Closed => 'Store closed',
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
