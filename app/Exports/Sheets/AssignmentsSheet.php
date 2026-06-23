<?php

namespace App\Exports\Sheets;

use App\Models\Assignment;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithTitle;

class AssignmentsSheet implements FromCollection, WithHeadings, WithMapping, WithTitle
{
    public function title(): string
    {
        return 'Assignments';
    }

    public function collection(): Collection
    {
        return Assignment::with('ticketIssues')->orderBy('id')->get();
    }

    /**
     * @return array<int, string>
     */
    public function headings(): array
    {
        return ['ID', 'Assigned Date', 'Assigned Hour', 'Mistaken', 'Ticket Issue IDs', 'Created By', 'Created At'];
    }

    /**
     * @param  Assignment  $assignment
     * @return array<int, mixed>
     */
    public function map($assignment): array
    {
        return [
            $assignment->id,
            $assignment->assigned_date?->toDateString(),
            $assignment->assigned_hour,
            $assignment->mistaken ? 'yes' : 'no',
            $assignment->ticketIssues->pluck('id')->implode(', '),
            $assignment->created_by,
            (string) $assignment->created_at,
        ];
    }
}
