<?php

namespace App\Exports\Sheets;

use App\Models\IssueStatusChange;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithTitle;

class IssueStatusChangesSheet implements FromCollection, WithHeadings, WithMapping, WithTitle
{
    public function title(): string
    {
        return 'Issue Status Changes';
    }

    public function collection(): Collection
    {
        return IssueStatusChange::query()->orderBy('id')->get();
    }

    /**
     * @return array<int, string>
     */
    public function headings(): array
    {
        return ['ID', 'Ticket Issue ID', 'From', 'To', 'Reason', 'Changed By', 'Changed At'];
    }

    /**
     * @param  IssueStatusChange  $change
     * @return array<int, mixed>
     */
    public function map($change): array
    {
        return [
            $change->id,
            $change->ticket_issue_id,
            $change->from_status?->value,
            $change->to_status->value,
            $change->reason,
            $change->created_by,
            (string) $change->created_at,
        ];
    }
}
