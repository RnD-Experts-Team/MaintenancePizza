<?php

namespace App\Enums;

/**
 * What one line of a payment paid for (docs/MAINTENANCE.md §4). Each line
 * is for one store, or one storage location for parts bought for the shelf.
 */
enum PaymentLineType: string
{
    case Hourly = 'hourly';
    case Fixed = 'fixed';
    case Inspection = 'inspection';
    case Parts = 'parts';
    case Gas = 'gas';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Hourly => 'By the hour',
            self::Fixed => 'Fixed price',
            self::Inspection => 'Inspection',
            self::Parts => 'Parts',
            self::Gas => 'Gas / mileage',
            self::Other => 'Other',
        };
    }

    /**
     * One plain sentence for the person filling in the line.
     */
    public function explanation(): string
    {
        return match ($this) {
            self::Hourly => 'The time the technician was on the clock, breaks not counted, times his hourly rate.',
            self::Fixed => 'One agreed price for the job at this store, however long it took.',
            self::Inspection => 'The price of going out to look at the problem and give a quote.',
            self::Parts => 'Parts bought: how many, times the price of one, for a ticket or for storage.',
            self::Gas => 'Gas or mileage: the amount paid, or the miles driven times the rate per mile.',
            self::Other => 'Anything else, described in words.',
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
