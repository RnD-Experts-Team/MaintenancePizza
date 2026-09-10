<?php

namespace App\Enums;

/**
 * Whether a record that costs somebody money has been settled through a daily
 * pay sheet yet.
 *
 * Being on a pay sheet IS being paid — there is no separate "money sent" step,
 * so the moment a payment covers a record it reads as paid.
 *
 * Derived on read from the aggregation claim tables; never stored, so it cannot
 * drift from the pay sheets it describes.
 */
enum PaymentStatus: string
{
    case Unpaid = 'unpaid';
    case Paid = 'paid';
    case NotPayable = 'not_payable';

    public function label(): string
    {
        return match ($this) {
            self::Unpaid => 'Not yet paid',
            self::Paid => 'Paid',
            self::NotPayable => 'Nothing to pay',
        };
    }

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_map(fn (self $c) => $c->value, self::cases());
    }

    /**
     * Roll several records' statuses into one. Anything still owed dominates;
     * a set with nothing owed and nothing paid has nothing to pay at all.
     *
     * @param  iterable<self>  $statuses
     */
    public static function rollUp(iterable $statuses): self
    {
        $seenPaid = false;

        foreach ($statuses as $status) {
            if ($status === self::Unpaid) {
                return self::Unpaid;
            }

            $seenPaid = $seenPaid || $status === self::Paid;
        }

        return $seenPaid ? self::Paid : self::NotPayable;
    }
}
