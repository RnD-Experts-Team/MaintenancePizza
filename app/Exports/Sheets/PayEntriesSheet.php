<?php

namespace App\Exports\Sheets;

use App\Models\PayEntry;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithTitle;

class PayEntriesSheet implements FromCollection, WithHeadings, WithMapping, WithTitle
{
    public function title(): string
    {
        return 'Pay Entries';
    }

    public function collection(): Collection
    {
        return PayEntry::with('ticketIssues')->orderBy('id')->get();
    }

    /**
     * @return array<int, string>
     */
    public function headings(): array
    {
        return [
            'ID', 'Technician ID', 'Base Pay', 'Performance Pay', 'Driving Time', 'Miles Driven',
            'Per Mile Rate', 'Driving Base Pay', 'Driving Performance Pay', 'Mistaken', 'Ticket Issue IDs', 'Created By', 'Created At',
        ];
    }

    /**
     * @param  PayEntry  $entry
     * @return array<int, mixed>
     */
    public function map($entry): array
    {
        return [
            $entry->id,
            $entry->technician_id,
            $entry->base_pay,
            $entry->performance_pay,
            $entry->driving_time,
            $entry->miles_driven,
            $entry->per_mile_rate,
            $entry->driving_base_pay,
            $entry->driving_performance_pay,
            $entry->mistaken ? 'yes' : 'no',
            $entry->ticketIssues->pluck('id')->implode(', '),
            $entry->created_by,
            (string) $entry->created_at,
        ];
    }
}
