<?php

namespace App\Exports\Sheets;

use App\Models\Technician;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithTitle;

class TechnicianTicketIssuesSheet implements FromCollection, WithHeadings, WithMapping, WithTitle
{
    public function title(): string
    {
        return 'Technician Ticket Issues';
    }

    public function collection(): Collection
    {
        return Technician::withTrashed()
            ->with('ticketIssues')
            ->orderBy('id')
            ->get()
            ->flatMap(fn (Technician $technician) => $technician->ticketIssues->map(fn ($ticketIssue) => (object) [
                'technician_id' => $technician->id,
                'ticket_issue_id' => $ticketIssue->id,
                'created_by' => $ticketIssue->pivot->created_by,
                'created_at' => $ticketIssue->pivot->created_at,
            ]))
            ->sortBy(['technician_id', 'ticket_issue_id'])
            ->values();
    }

    /**
     * @return array<int, string>
     */
    public function headings(): array
    {
        return ['Technician ID', 'Ticket Issue ID', 'Created By', 'Created At'];
    }

    /**
     * @param  object  $link
     * @return array<int, mixed>
     */
    public function map($link): array
    {
        return [
            $link->technician_id,
            $link->ticket_issue_id,
            $link->created_by,
            (string) $link->created_at,
        ];
    }
}
