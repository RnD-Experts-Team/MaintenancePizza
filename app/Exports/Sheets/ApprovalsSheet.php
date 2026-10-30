<?php

namespace App\Exports\Sheets;

use App\Models\IssueApproval;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithTitle;

class ApprovalsSheet implements FromCollection, WithHeadings, WithMapping, WithTitle
{
    public function title(): string
    {
        return 'Approvals';
    }

    public function collection(): Collection
    {
        return IssueApproval::query()->orderBy('id')->get();
    }

    /**
     * @return array<int, string>
     */
    public function headings(): array
    {
        return [
            'ID', 'Ticket Issue ID', 'Inspection ID', 'Amount', 'Level', 'Decision', 'Approver Name',
            'Approver User ID', 'Decided At', 'Note', 'Mistaken', 'Created By', 'Created At',
        ];
    }

    /**
     * @param  IssueApproval  $approval
     * @return array<int, mixed>
     */
    public function map($approval): array
    {
        return [
            $approval->id,
            $approval->ticket_issue_id,
            $approval->issue_inspection_id,
            $approval->amount,
            $approval->level,
            $approval->decision?->label(),
            $approval->approver_name,
            $approval->approver_user_id,
            (string) $approval->decided_at,
            $approval->note,
            $approval->mistaken ? 'yes' : 'no',
            $approval->created_by,
            (string) $approval->created_at,
        ];
    }
}
