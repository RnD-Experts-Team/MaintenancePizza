<?php

namespace App\Exports\Sheets;

use App\Models\Payment;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithTitle;

/** One row per payment, mistaken ones included (flagged). Lines: the next sheet. */
class PaymentsSheet implements FromCollection, WithHeadings, WithMapping, WithTitle
{
    public function title(): string
    {
        return 'Payments';
    }

    public function collection(): Collection
    {
        return Payment::with(['technician', 'supplier'])->withCount('lines')->orderBy('id')->get();
    }

    /**
     * @return array<int, string>
     */
    public function headings(): array
    {
        return [
            'ID', 'Paid At', 'Paid To Type', 'Paid To ID', 'Paid To', 'Method', 'Reference', 'Invoice Number',
            'Total', 'Line Count', 'Mistaken', 'Created By', 'Created At', 'Updated At',
        ];
    }

    /**
     * @param  Payment  $payment
     * @return array<int, mixed>
     */
    public function map($payment): array
    {
        $to = $payment->paidTo();

        return [
            $payment->id,
            $payment->paid_at?->toDateString(),
            $to['type'] ?? null,
            $to['id'] ?? null,
            $to['name'] ?? null,
            $payment->method?->label(),
            $payment->reference,
            $payment->invoice_number,
            $payment->total,
            $payment->lines_count,
            $payment->mistaken ? 'yes' : 'no',
            $payment->created_by,
            (string) $payment->created_at,
            (string) $payment->updated_at,
        ];
    }
}
