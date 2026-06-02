<?php

namespace App\Exports\Sheets;

use App\Models\TicketIssue;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithTitle;

class TicketIssuesSheet implements FromCollection, WithHeadings, WithMapping, WithTitle
{
    public function title(): string
    {
        return 'Ticket Issues';
    }

    public function collection(): Collection
    {
        return TicketIssue::with('issue')->orderBy('id')->get();
    }

    /**
     * @return array<int, string>
     */
    public function headings(): array
    {
        return ['ID', 'Ticket ID', 'Title', 'Priority', 'Status', 'Description', 'Parent ID', 'Created By', 'Created At'];
    }

    /**
     * @param  TicketIssue  $issue
     * @return array<int, mixed>
     */
    public function map($issue): array
    {
        return [
            $issue->id,
            $issue->ticket_id,
            $issue->displayTitle(),
            $issue->priority->value,
            $issue->status->value,
            $issue->description,
            $issue->parent_id,
            $issue->created_by,
            (string) $issue->created_at,
        ];
    }
}
