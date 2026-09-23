<?php

namespace App\Enums;

/**
 * Who actually paid for a part. Anything not paid by us is reimbursable, and
 * is what a daily pay gathers up for the technician who fronted the money.
 */
enum PartUsagePayer: string
{
    case Us = 'us';
    case Technician = 'technician';

    public function label(): string
    {
        return match ($this) {
            self::Us => 'Paid by us',
            self::Technician => 'Paid by the technician',
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
