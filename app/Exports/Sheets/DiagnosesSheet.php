<?php

namespace App\Exports\Sheets;

use App\Models\Diagnosis;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithTitle;

class DiagnosesSheet implements FromCollection, WithHeadings, WithMapping, WithTitle
{
    public function title(): string
    {
        return 'Diagnoses';
    }

    public function collection(): Collection
    {
        return Diagnosis::with('ticketIssues')->withCount('attachments')->orderBy('id')->get();
    }

    /**
     * @return array<int, string>
     */
    public function headings(): array
    {
        return ['ID', 'Body', 'Mistaken', 'Ticket Issue IDs', 'Attachments', 'Created By', 'Created At'];
    }

    /**
     * @param  Diagnosis  $diagnosis
     * @return array<int, mixed>
     */
    public function map($diagnosis): array
    {
        return [
            $diagnosis->id,
            $diagnosis->body,
            $diagnosis->mistaken ? 'yes' : 'no',
            $diagnosis->ticketIssues->pluck('id')->implode(', '),
            $diagnosis->attachments_count,
            $diagnosis->created_by,
            (string) $diagnosis->created_at,
        ];
    }
}
