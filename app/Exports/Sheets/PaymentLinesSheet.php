<?php

namespace App\Exports\Sheets;

use App\Models\PaymentLine;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithTitle;

class PaymentLinesSheet implements FromCollection, WithHeadings, WithMapping, WithTitle
{
    public function title(): string
    {
        return 'Payment Lines';
    }

    public function collection(): Collection
    {
        return PaymentLine::with(['store', 'storageLocation', 'part', 'boughtFrom', 'ticketIssues', 'tripSessions'])
            ->orderBy('payment_id')->orderBy('sort_order')->orderBy('id')
            ->get();
    }

    /**
     * @return array<int, string>
     */
    public function headings(): array
    {
        return [
            'ID', 'Payment ID', 'Type', 'Store', 'Other Store', 'Storage Location', 'Description', 'Amount',
            'Hours', 'Rate', 'Hours Overridden', 'Part', 'Quantity', 'Unit Cost', 'Bought From', 'Miles',
            'Inspection ID', 'Ticket Issue IDs', 'Trip Session IDs', 'Created At',
        ];
    }

    /**
     * @param  PaymentLine  $line
     * @return array<int, mixed>
     */
    public function map($line): array
    {
        return [
            $line->id,
            $line->payment_id,
            $line->type?->label(),
            $line->store?->store_number,
            $line->other_store,
            $line->storageLocation?->name,
            $line->description,
            $line->amount,
            $line->hours,
            $line->rate,
            $line->hours_overridden ? 'yes' : 'no',
            $line->part?->name,
            $line->quantity,
            $line->unit_cost,
            $line->boughtFrom?->name,
            $line->miles,
            $line->issue_inspection_id,
            $line->ticketIssues->pluck('id')->implode(', '),
            $line->tripSessions->pluck('id')->implode(', '),
            (string) $line->created_at,
        ];
    }
}
