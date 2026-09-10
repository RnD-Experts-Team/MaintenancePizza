<?php

namespace App\Exports\Sheets;

use App\Models\StockBalance;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithTitle;

class StockBalancesSheet implements FromCollection, WithHeadings, WithMapping, WithTitle
{
    public function title(): string
    {
        return 'Stock Balances';
    }

    public function collection(): Collection
    {
        return StockBalance::with(['part', 'storageLocation'])
            ->orderBy('part_id')->orderBy('storage_location_id')->get();
    }

    /**
     * @return array<int, string>
     */
    public function headings(): array
    {
        return ['Part ID', 'Part', 'Location ID', 'Location', 'Quantity', 'Updated At'];
    }

    /**
     * @param  StockBalance  $balance
     * @return array<int, mixed>
     */
    public function map($balance): array
    {
        return [
            $balance->part_id,
            $balance->part?->name,
            $balance->storage_location_id,
            $balance->storageLocation?->name,
            $balance->quantity,
            (string) $balance->updated_at,
        ];
    }
}
