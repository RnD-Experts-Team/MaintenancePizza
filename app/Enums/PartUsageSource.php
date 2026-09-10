<?php

namespace App\Enums;

/**
 * Where a part came from: bought for the job, or taken off our own shelf.
 * Drawing from storage moves stock; buying does not.
 */
enum PartUsageSource: string
{
    case Purchased = 'purchased';
    case FromStorage = 'from_storage';

    public function label(): string
    {
        return match ($this) {
            self::Purchased => 'Purchased',
            self::FromStorage => 'Taken from storage',
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
