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
            'ID', 'Daily Pay Entry ID', 'Daily Pay Payment ID', 'Technician ID', 'Store ID',
            'Total Working Hours', 'Travel Time', 'Total Break Time', 'Parts Run Time', 'Hours Overridden',
            'Gas', 'Lump Sum', 'Hourly Payment Rate', 'Money Owed',
            'Gathered Work Hours', 'Gathered Travel Hours', 'Gathered Break Hours',
            'Gathered Parts Run Hours', 'Gathered Reimbursable Parts',
            'Line Total', 'Ticket Issue IDs', 'Created By', 'Created At',
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
            $line->daily_pay_payment_id,
            $line->technician_id,
            $line->store_id,
            $line->total_working_hours,
            $line->travel_time,
            $line->total_break_time,
            $line->parts_run_time,
            $line->hours_overridden ? 'yes' : 'no',
            $line->gas,
            $line->lump_sum,
            $line->hourly_payment_rate,
            $line->money_owed,
            $line->frozen_work_hours,
            $line->frozen_travel_hours,
            $line->frozen_break_hours,
            $line->frozen_parts_run_hours,
            $line->frozen_reimbursable_parts,
            $line->line_total,
            $line->ticketIssues->pluck('id')->implode(', '),
            $line->created_by,
            (string) $line->created_at,
        ];
    }
}
