<?php

declare(strict_types=1);

namespace App\Reports\Datasets;

use App\Enums\PaymentMethod;
use App\Reports\Contract\Column;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * One row per payment (an invoice paid to one technician or one supplier), on
 * the day it was paid. A payment can split across stores, so it has no store:
 * the per-store money is `maintenance.payment_lines`. Mistaken payments are
 * left out.
 */
class PaymentsDataset extends GlobalDataset
{
    public const KEY = 'maintenance.payments';

    public function label(): string
    {
        return 'Payments';
    }

    public function grain(): array
    {
        return ['date'];
    }

    public function columns(): array
    {
        return [
            new Column('date', 'Paid date', 'date', 'payments.paid_at', groupable: true),
            new Column('technician', 'Technician', 'string', 'technicians.name', groupable: true),
            new Column('supplier', 'Supplier', 'string', 'suppliers.name', groupable: true),
            new Column(
                'payee',
                'Paid to',
                'enum',
                "CASE WHEN payments.technician_id IS NOT NULL THEN 'technician' WHEN payments.supplier_id IS NOT NULL THEN 'supplier' ELSE 'unknown' END",
                options: [
                    ['value' => 'technician', 'label' => 'A technician'],
                    ['value' => 'supplier', 'label' => 'A supplier'],
                    ['value' => 'unknown', 'label' => 'Not recorded'],
                ],
                groupable: true,
            ),
            new Column(
                'method',
                'Method',
                'enum',
                'payments.method',
                options: array_map(fn (PaymentMethod $m) => ['value' => $m->value, 'label' => $m->label()], PaymentMethod::cases()),
                groupable: true,
            ),
            new Column('payments', 'Payments', 'integer', 'payments.id', measure: true, aggregations: ['count']),
            new Column('total', 'Total paid', 'money', 'payments.total', measure: true, aggregations: ['sum', 'avg', 'min', 'max']),
        ];
    }

    public function baseQuery(): Builder
    {
        return DB::table('payments')
            ->leftJoin('technicians', 'technicians.id', '=', 'payments.technician_id')
            ->leftJoin('suppliers', 'suppliers.id', '=', 'payments.supplier_id')
            ->where('payments.mistaken', false);
    }

    public function dateColumn(): string
    {
        return 'payments.paid_at';
    }
}
