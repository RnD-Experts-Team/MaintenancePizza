<?php

namespace App\Exports\Sheets;

use App\Models\StockCount;
use App\Models\StockCountLine;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithTitle;

/**
 * One row per stock count; its lines are listed in one cell as
 * "part: expected → counted" (counted "—" while nobody has counted it).
 */
class StockCountsSheet implements FromCollection, WithHeadings, WithMapping, WithTitle
{
    public function title(): string
    {
        return 'Stock Counts';
    }

    public function collection(): Collection
    {
        return StockCount::with(['storageLocation', 'placeValue', 'lines.part' => fn ($q) => $q->withTrashed()])
            ->orderBy('id')
            ->get();
    }

    /**
     * @return array<int, string>
     */
    public function headings(): array
    {
        return [
            'ID', 'Location', 'Place', 'Status', 'Started At', 'Applied At', 'Applied By', 'Missing Value',
            'Extra Value', 'Line Count', 'Counted Lines', 'Lines', 'Notes', 'Created By', 'Created At',
        ];
    }

    /**
     * @param  StockCount  $count
     * @return array<int, mixed>
     */
    public function map($count): array
    {
        return [
            $count->id,
            $count->storageLocation?->name,
            $count->placeValue?->value,
            $count->status,
            (string) $count->started_at,
            (string) $count->applied_at,
            $count->applied_by,
            $count->missing_value,
            $count->extra_value,
            $count->lines->count(),
            $count->lines->whereNotNull('counted')->count(),
            $count->lines
                ->map(fn (StockCountLine $l) => ($l->part?->name ?? "Part #{$l->part_id}") . ': ' . $l->expected . ' → ' . ($l->counted ?? '—'))
                ->implode('; '),
            $count->notes_text,
            $count->created_by,
            (string) $count->created_at,
        ];
    }
}
