<?php

namespace App\Exports\Sheets;

use App\Models\DailyPayLine;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithTitle;

class DailyPayLinesSheet implements FromCollection, WithHeadings, WithMapping, WithTitle
{
    public function title(): string
    {
        return 'Daily Pay Lines';
    }

    public function collection(): Collection
    {
        return DailyPayLine::with('ticketIssues')->orderBy('id')->get();
    }

    /**
     * @return array<int, string>
     */
    public function headings(): array
    {
        return [
            'ID', 'Daily Pay Entry ID', 'Technician ID', 'Store ID',
            'Total Working Hours', 'Gas', 'Invoices', 'Hourly Payment Rate',
            'Money Owed', 'Travel Time', 'Total Break Time',
            'Ticket Issue IDs', 'Created By', 'Created At',
        ];
    }

    /**
     * @param  DailyPayLine  $line
     * @return array<int, mixed>
     */
    public function map($line): array
    {
        return [
            $line->id,
            $line->daily_pay_entry_id,
            $line->technician_id,
            $line->store_id,
            $line->total_working_hours,
            $line->gas,
            $line->invoices,
            $line->hourly_payment_rate,
            $line->money_owed,
            $line->travel_time,
            $line->total_break_time,
            $line->ticketIssues->pluck('id')->implode(', '),
            $line->created_by,
            (string) $line->created_at,
        ];
    }
}
