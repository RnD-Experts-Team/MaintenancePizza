<?php

namespace App\Exports\Sheets;

use App\Models\IssueInspection;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithTitle;

class InspectionsSheet implements FromCollection, WithHeadings, WithMapping, WithTitle
{
    public function title(): string
    {
        return 'Inspections';
    }

    public function collection(): Collection
    {
        return IssueInspection::with(['technician' => fn ($q) => $q->withTrashed()])->orderBy('id')->get();
    }

    /**
     * @return array<int, string>
     */
    public function headings(): array
    {
        return [
            'ID', 'Ticket Issue ID', 'Technician', 'Status', 'Scheduled Date', 'Scheduled Time', 'Done At',
            'Findings', 'Recommended Repair', 'Parts Needed', 'Estimated Cost', 'Mistaken', 'Created By', 'Created At',
        ];
    }

    /**
     * @param  IssueInspection  $inspection
     * @return array<int, mixed>
     */
    public function map($inspection): array
    {
        return [
            $inspection->id,
            $inspection->ticket_issue_id,
            $inspection->technician?->name,
            $inspection->status?->label(),
            $inspection->scheduled_date?->toDateString(),
            $inspection->scheduled_time,
            (string) $inspection->done_at,
            $inspection->findings,
            $inspection->recommended_repair,
            $inspection->parts_needed,
            $inspection->estimated_cost,
            $inspection->mistaken ? 'yes' : 'no',
            $inspection->created_by,
            (string) $inspection->created_at,
        ];
    }
}
