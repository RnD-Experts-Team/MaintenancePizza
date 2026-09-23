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
        return AttendanceEntry::with(['events', 'ticketIssues', 'dailyPayPayments'])->withCount('attachments')->orderBy('id')->get();
    }

    /**
     * @return array<int, string>
     */
    public function headings(): array
    {
        return [
            // The six raw break/parts-run/travel columns are gone. A session
            // can now hold SEVERAL breaks, and one row cannot hold three of
            // them -- a first-of-each would have been a quiet lie. The hours
            // columns below already carry the whole figure, so nothing is lost
            // at the level this sheet reports at.
            'ID', 'Technician ID', 'Start Clock', 'End Clock', 'Events',
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
            // How many things happened, in place of the six columns. The
            // detail is in the hours below; this is the "was there more to
            // this day than a clock-in and out" signal.
            $entry->liveEvents()->count(),
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
