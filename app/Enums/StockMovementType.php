<?php

namespace App\Enums;

/**
 * Why stock moved, in the words the people at the shelf use
 * (docs/MAINTENANCE-V2.md §5). The stored values were renamed from the old
 * ledger jargon by the inventory_v2 migration; quantities and directions
 * were not touched.
 *
 * Where each comes from:
 * - bought:        only a parts payment line going into storage;
 * - stock_count:   only applying a stock count;
 * - undone:        only marking a movement mistaken (the equal-and-opposite);
 * - the rest:      typed in on the storage page (POST stock-movements).
 */
enum StockMovementType: string
{
    case Bought = 'bought';
    case Used = 'used';
    case Returned = 'returned';
    case Moved = 'moved';
    case OpeningStock = 'opening_stock';
    case StockCount = 'stock_count';
    case DamagedLost = 'damaged_lost';
    case Undone = 'undone';

    public function label(): string
    {
        return match ($this) {
            self::Bought => 'Bought',
            self::Used => 'Used',
            self::Returned => 'Returned',
            self::Moved => 'Moved',
            self::OpeningStock => 'Opening stock',
            self::StockCount => 'Stock count',
            self::DamagedLost => 'Damaged or lost',
            self::Undone => 'Undone',
        };
    }

    /**
     * Which way this type moves stock: +1 in, -1 out, 0 when each line says
     * for itself (a move is one movement with an outbound line at A and an
     * inbound line at B; a count or an undo can go either way).
     */
    public function defaultDirection(): int
    {
        return match ($this) {
            self::Bought, self::Returned, self::OpeningStock => 1,
            self::Used, self::DamagedLost => -1,
            self::Moved, self::StockCount, self::Undone => 0,
        };
    }

    /**
     * The types a person may record by hand on the storage page. The others
     * are only ever written as the consequence of something else.
     *
     * @return array<int, self>
     */
    public static function manual(): array
    {
        return [self::Used, self::Returned, self::Moved, self::OpeningStock, self::DamagedLost];
    }

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_map(fn (self $c) => $c->value, self::cases());
    }
}
