<?php

namespace App\Exports\Sheets;

use App\Models\StockMovement;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithTitle;

class StockMovementsSheet implements FromCollection, WithHeadings, WithMapping, WithTitle
{
    public function title(): string
    {
        return 'Stock Movements';
    }

    public function collection(): Collection
    {
        return StockMovement::with(['storageLocation', 'paidByTechnician'])->orderBy('id')->get();
    }

    /**
     * @return array<int, string>
     */
    public function headings(): array
    {
        return [
            'ID', 'Moved At', 'Type', 'Location', 'Paid By', 'Paid By Technician',
            'Body', 'Part Usage ID', 'Reverses Movement ID', 'Mistaken', 'Created By', 'Created At',
        ];
    }

    /**
     * @param  StockMovement  $movement
     * @return array<int, mixed>
     */
    public function map($movement): array
    {
        return [
            $movement->id,
            (string) $movement->moved_at,
            $movement->type->label(),
            $movement->storageLocation?->name,
            $movement->paid_by?->label(),
            $movement->paidByTechnician?->name,
            $movement->body,
            $movement->part_usage_id,
            $movement->reverses_stock_movement_id,
            $movement->mistaken ? 'yes' : 'no',
            $movement->created_by,
            (string) $movement->created_at,
        ];
    }
}
