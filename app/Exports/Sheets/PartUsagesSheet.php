<?php

namespace App\Exports\Sheets;

use App\Models\PartUsage;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithTitle;

class PartUsagesSheet implements FromCollection, WithHeadings, WithMapping, WithTitle
{
    public function title(): string
    {
        return 'Part Usages';
    }

    public function collection(): Collection
    {
        return PartUsage::with([
            'part',
            'ticketIssues',
            'paidByTechnician',
            'storageLocation',
            'returnedToStorageLocation',
            'dailyPayPayments',
        ])->withCount('attachments')->orderBy('id')->get();
    }

    /**
     * @return array<int, string>
     */
    public function headings(): array
    {
        return [
            'ID', 'Part', 'Quantity', 'Unit Cost', 'Cost (gross)',
            'Returned Quantity', 'Returned To', 'Net Quantity', 'Net Cost',
            'Source', 'Paid By', 'Paid By Technician', 'Taken From',
            'Payment Status', 'Reimbursed On Pay Sheets',
            'Mistaken', 'Ticket Issue IDs', 'Attachments', 'Created By', 'Created At',
        ];
    }

    /**
     * @param  PartUsage  $usage
     * @return array<int, mixed>
     */
    public function map($usage): array
    {
        return [
            $usage->id,
            $usage->part?->name,
            $usage->quantity,
            $usage->unit_cost,
            $usage->cost,
            $usage->returned_quantity,
            $usage->returnedToStorageLocation?->name,
            $usage->netQuantity(),
            $usage->netCost(),
            $usage->source->label(),
            $usage->paid_by->label(),
            $usage->paidByTechnician?->name,
            $usage->storageLocation?->name,
            $usage->paymentStatus()?->label(),
            $usage->dailyPayPayments->pluck('id')->implode(', '),
            $usage->mistaken ? 'yes' : 'no',
            $usage->ticketIssues->pluck('id')->implode(', '),
            $usage->attachments_count,
            $usage->created_by,
            (string) $usage->created_at,
        ];
    }
}
