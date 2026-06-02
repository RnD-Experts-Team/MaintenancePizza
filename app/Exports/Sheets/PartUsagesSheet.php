<?php

namespace App\Exports\Sheets;

use App\Models\PartUsage;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithTitle;

class PartUsagesSheet implements FromCollection, WithHeadings, WithMapping, WithTitle
{
    public function title(): string
    {
        return 'Part Usages';
    }

    public function collection(): Collection
    {
        return PartUsage::with(['part', 'ticketIssues'])->withCount('attachments')->orderBy('id')->get();
    }

    /**
     * @return array<int, string>
     */
    public function headings(): array
    {
        return ['ID', 'Part', 'Cost', 'Mistaken', 'Ticket Issue IDs', 'Attachments', 'Created By', 'Created At'];
    }

    /**
     * @param  PartUsage  $usage
     * @return array<int, mixed>
     */
    public function map($usage): array
    {
        return [
            $usage->id,
            $usage->part?->name,
            $usage->cost,
            $usage->mistaken ? 'yes' : 'no',
            $usage->ticketIssues->pluck('id')->implode(', '),
            $usage->attachments_count,
            $usage->created_by,
            (string) $usage->created_at,
        ];
    }
}
