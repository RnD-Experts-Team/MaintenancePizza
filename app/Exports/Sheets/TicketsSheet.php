<?php

namespace App\Exports\Sheets;

use App\Models\Ticket;
use App\Services\TicketStatusService;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithTitle;

class TicketsSheet implements FromCollection, WithHeadings, WithMapping, WithTitle
{
    public function title(): string
    {
        return 'Tickets';
    }

    public function collection(): Collection
    {
        return Ticket::withTrashed()->with(['store', 'ticketIssues'])->orderBy('id')->get();
    }

    /**
     * @return array<int, string>
     */
    public function headings(): array
    {
        return ['ID', 'Store Number', 'Derived Status', 'Final Note', 'Issues', 'Created By', 'Created At', 'Deleted At'];
    }

    /**
     * @param  Ticket  $ticket
     * @return array<int, mixed>
     */
    public function map($ticket): array
    {
        return [
            $ticket->id,
            $ticket->store?->store_number,
            TicketStatusService::for($ticket)->value,
            $ticket->final_note,
            $ticket->ticketIssues->count(),
            $ticket->created_by,
            (string) $ticket->created_at,
            (string) $ticket->deleted_at,
        ];
    }
}
