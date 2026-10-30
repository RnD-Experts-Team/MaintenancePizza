<?php

namespace App\Enums;

/**
 * How a payment was made. Null on a payment means "not recorded": rows
 * converted from the old pay sheets never said.
 */
enum PaymentMethod: string
{
    case Cash = 'cash';
    case Check = 'check';
    case Card = 'card';
    case BankTransfer = 'bank_transfer';
    case Zelle = 'zelle';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Cash => 'Cash',
            self::Check => 'Check',
            self::Card => 'Card',
            self::BankTransfer => 'Bank transfer',
            self::Zelle => 'Zelle',
            self::Other => 'Other',
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
