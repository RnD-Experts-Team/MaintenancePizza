<?php

namespace App\Exports\Sheets;

use App\Models\Warranty;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithTitle;

class WarrantiesSheet implements FromCollection, WithHeadings, WithMapping, WithTitle
{
    public function title(): string
    {
        return 'Warranties';
    }

    public function collection(): Collection
    {
        return Warranty::with('ticketIssues')->withCount('attachments')->orderBy('id')->get();
    }

    /**
     * @return array<int, string>
     */
    public function headings(): array
    {
        return ['ID', 'Body', 'Ticket Issue IDs', 'Attachments', 'Created By', 'Created At'];
    }

    /**
     * @param  Warranty  $warranty
     * @return array<int, mixed>
     */
    public function map($warranty): array
    {
        return [
            $warranty->id,
            $warranty->body,
            $warranty->ticketIssues->pluck('id')->implode(', '),
            $warranty->attachments_count,
            $warranty->created_by,
            (string) $warranty->created_at,
        ];
    }
}
