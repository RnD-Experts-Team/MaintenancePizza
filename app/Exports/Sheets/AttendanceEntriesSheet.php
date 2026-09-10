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
        return AttendanceEntry::with(['ticketIssues', 'dailyPayPayments'])->withCount('attachments')->orderBy('id')->get();
    }

    /**
     * @return array<int, string>
     */
    public function headings(): array
    {
        return [
            'ID', 'Technician ID', 'Start Clock', 'End Clock', 'Start Break', 'End Break',
            'Start Parts Run', 'End Parts Run', 'Start Travel', 'End Travel',
            'Work Hours', 'Travel Hours', 'Break Hours', 'Parts Run Hours', 'Duration Warnings',
            'Payment Status', 'Paid On Pay Sheets',
            'Mistaken', 'Ticket Issue IDs', 'Attachments', 'Created By', 'Created At',
        ];
    }

    /**
     * @param  AttendanceEntry  $entry
     * @return array<int, mixed>
     */
    public function map($entry): array
    {
        $durations = $entry->durations();

        return [
            $entry->id,
            $entry->technician_id,
            (string) $entry->start_clock,
            (string) $entry->end_clock,
            (string) $entry->start_break,
            (string) $entry->end_break,
            (string) $entry->start_parts_run,
            (string) $entry->end_parts_run,
            (string) $entry->start_travel,
            (string) $entry->end_travel,
            round($durations['work'] / 60, 2),
            round($durations['travel'] / 60, 2),
            round($durations['break'] / 60, 2),
            round($durations['parts_run'] / 60, 2),
            implode(', ', $durations['warnings']),
            $entry->paymentStatus()?->label(),
            $entry->dailyPayPayments->pluck('id')->implode(', '),
            $entry->mistaken ? 'yes' : 'no',
            $entry->ticketIssues->pluck('id')->implode(', '),
            $entry->attachments_count,
            $entry->created_by,
            (string) $entry->created_at,
        ];
    }
}
