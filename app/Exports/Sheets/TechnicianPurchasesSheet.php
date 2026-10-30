<?php

namespace App\Exports\Sheets;

use App\Models\TechnicianPurchase;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithTitle;

class TechnicianPurchasesSheet implements FromCollection, WithHeadings, WithMapping, WithTitle
{
    public function title(): string
    {
        return 'Technician Purchases';
    }

    public function collection(): Collection
    {
        return TechnicianPurchase::with([
            'technician' => fn ($q) => $q->withTrashed(),
            'part' => fn ($q) => $q->withTrashed(),
            'supplier' => fn ($q) => $q->withTrashed(),
            'paymentLine',
        ])->orderBy('id')->get();
    }

    /**
     * @return array<int, string>
     */
    public function headings(): array
    {
        return [
            'ID', 'Technician', 'Trip ID', 'Trip Stop ID', 'Ticket Issue ID', 'Part', 'Description', 'Quantity',
            'Total Cost', 'Supplier', 'Serial Number', 'Bought At', 'Paid', 'Payment Line ID', 'Payment ID',
            'Mistaken', 'Created By', 'Created At',
        ];
    }

    /**
     * @param  TechnicianPurchase  $purchase
     * @return array<int, mixed>
     */
    public function map($purchase): array
    {
        return [
            $purchase->id,
            $purchase->technician?->name,
            $purchase->trip_id,
            $purchase->trip_stop_id,
            $purchase->ticket_issue_id,
            $purchase->part?->name,
            $purchase->description,
            $purchase->quantity,
            $purchase->total_cost,
            $purchase->supplier?->name,
            $purchase->serial_number,
            (string) $purchase->bought_at,
            $purchase->isPaid() ? 'yes' : 'no',
            $purchase->payment_line_id,
            $purchase->paymentLine?->payment_id,
            $purchase->mistaken ? 'yes' : 'no',
            $purchase->created_by,
            (string) $purchase->created_at,
        ];
    }
}
