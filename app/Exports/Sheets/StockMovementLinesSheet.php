<?php

namespace App\Exports\Sheets;

use App\Models\StockMovementLine;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithTitle;

class StockMovementLinesSheet implements FromCollection, WithHeadings, WithMapping, WithTitle
{
    public function title(): string
    {
        return 'Stock Movement Lines';
    }

    public function collection(): Collection
    {
        return StockMovementLine::with(['part', 'storageLocation'])->orderBy('id')->get();
    }

    /**
     * @return array<int, string>
     */
    public function headings(): array
    {
        return [
            'ID', 'Movement ID', 'Part', 'Location', 'Quantity', 'Direction',
            'Signed Quantity', 'Unit Cost', 'Total Cost', 'Created At',
        ];
    }

    /**
     * @param  StockMovementLine  $line
     * @return array<int, mixed>
     */
    public function map($line): array
    {
        return [
            $line->id,
            $line->stock_movement_id,
            $line->part?->name,
            $line->storageLocation?->name,
            $line->quantity,
            $line->direction,
            // What this line actually contributes to the balance.
            bcmul((string) $line->quantity, (string) $line->direction, 2),
            $line->unit_cost,
            $line->total_cost,
            (string) $line->created_at,
        ];
    }
}
