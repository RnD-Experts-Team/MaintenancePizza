<?php

namespace App\Exports\Sheets;

use App\Models\AttendanceEntry;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithTitle;

class AttendanceEntriesSheet implements FromCollection, WithHeadings, WithMapping, WithTitle
{
    public function title(): string
    {
        return 'Attendance Entries';
    }

    public function collection(): Collection
    {
        return AttendanceEntry::with('ticketIssues')->withCount('attachments')->orderBy('id')->get();
    }

    /**
     * @return array<int, string>
     */
    public function headings(): array
    {
        return [
            'ID', 'Technician ID', 'Start Clock', 'End Clock', 'Start Break', 'End Break',
            'Start Parts Run', 'End Parts Run', 'Mistaken', 'Ticket Issue IDs', 'Attachments', 'Created By', 'Created At',
        ];
    }

    /**
     * @param  AttendanceEntry  $entry
     * @return array<int, mixed>
     */
    public function map($entry): array
    {
        return [
            $entry->id,
            $entry->technician_id,
            (string) $entry->start_clock,
            (string) $entry->end_clock,
            (string) $entry->start_break,
            (string) $entry->end_break,
            (string) $entry->start_parts_run,
            (string) $entry->end_parts_run,
            $entry->mistaken ? 'yes' : 'no',
            $entry->ticketIssues->pluck('id')->implode(', '),
            $entry->attachments_count,
            $entry->created_by,
            (string) $entry->created_at,
        ];
    }
}
