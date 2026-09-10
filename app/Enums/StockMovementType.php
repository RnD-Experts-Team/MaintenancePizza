<?php

namespace App\Enums;

enum StockMovementType: string
{
    case Purchase = 'purchase';
    case Draw = 'draw';
    case Return = 'return';
    case TransferOut = 'transfer_out';
    case TransferIn = 'transfer_in';
    case Adjustment = 'adjustment';
    case InitialCount = 'initial_count';
    case Reversal = 'reversal';

    public function label(): string
    {
        return match ($this) {
            self::Purchase => 'Purchase',
            self::Draw => 'Drawn for a job',
            self::Return => 'Returned to storage',
            self::TransferOut => 'Transfer out',
            self::TransferIn => 'Transfer in',
            self::Adjustment => 'Adjustment',
            self::InitialCount => 'Initial count',
            self::Reversal => 'Reversal',
        };
    }

    /**
     * Which way this type moves stock: +1 in, -1 out, 0 when the type carries
     * both and each line says for itself (a transfer is one movement with an
     * outbound line at A and an inbound line at B; an adjustment or a reversal
     * can go either way).
     */
    public function defaultDirection(): int
    {
        return match ($this) {
            self::Purchase, self::Return, self::TransferIn, self::InitialCount => 1,
            self::Draw, self::TransferOut => -1,
            self::Adjustment, self::Reversal => 0,
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
