<?php

namespace App\Exports\Sheets;

use App\Models\DailyPayPayment;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithTitle;

class DailyPayPaymentsSheet implements FromCollection, WithHeadings, WithMapping, WithTitle
{
    public function title(): string
    {
        return 'Daily Pay Payments';
    }

    public function collection(): Collection
    {
        return DailyPayPayment::with('technician')->orderBy('id')->get();
    }

    /**
     * @return array<int, string>
     */
    public function headings(): array
    {
        return [
            'ID', 'Daily Pay Entry ID', 'Payee (Technician) ID', 'Payee',
            'Hourly Payment Rate', 'Lump Sum', 'Gas', 'Money Owed (extra)',
            'Gathered Work Hours', 'Gathered Travel Hours', 'Gathered Break Hours',
            'Gathered Parts Run Hours', 'Gathered Reimbursable Parts',
            'Lines Total', 'Total Amount', 'Gathered At', 'Gathered By',
            'Warnings', 'Created By', 'Created At',
        ];
    }

    /**
     * @param  DailyPayPayment  $payment
     * @return array<int, mixed>
     */
    public function map($payment): array
    {
        return [
            $payment->id,
            $payment->daily_pay_entry_id,
            $payment->technician_id,
            $payment->technician?->name,
            $payment->hourly_payment_rate,
            $payment->lump_sum,
            $payment->gas,
            $payment->money_owed,
            $payment->frozen_work_hours,
            $payment->frozen_travel_hours,
            $payment->frozen_break_hours,
            $payment->frozen_parts_run_hours,
            $payment->frozen_reimbursable_parts,
            $payment->lines_total,
            $payment->total_amount,
            (string) $payment->aggregated_at,
            $payment->aggregated_by,
            implode('; ', array_column($payment->aggregation_warnings ?? [], 'code')),
            $payment->created_by,
            (string) $payment->created_at,
        ];
    }
}
