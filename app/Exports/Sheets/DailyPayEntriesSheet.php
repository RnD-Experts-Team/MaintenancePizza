<?php

namespace App\Exports\Sheets;

use App\Models\DailyPayEntry;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithTitle;

class DailyPayEntriesSheet implements FromCollection, WithHeadings, WithMapping, WithTitle
{
    public function title(): string
    {
        return 'Daily Pay Entries';
    }

    public function collection(): Collection
    {
        return DailyPayEntry::orderBy('id')->get();
    }

    /**
     * @return array<int, string>
     */
    public function headings(): array
    {
        return ['ID', 'Date', 'Created By', 'Created At'];
    }

    /**
     * @param  DailyPayEntry  $entry
     * @return array<int, mixed>
     */
    public function map($entry): array
    {
        return [
            $entry->id,
            $entry->date?->toDateString(),
            $entry->created_by,
            (string) $entry->created_at,
        ];
    }
}
