<?php

declare(strict_types=1);

namespace App\Reports\Datasets;

use App\Enums\PaymentLineType;
use App\Enums\PaymentMethod;
use App\Reports\Contract\Column;
use App\Reports\Contract\ReportDataset;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * What was paid, one row per payment line, on the day of its payment. A line
 * spends money at a store, at a store written in words (`other_store`), or on
 * a storage shelf (parts), so `store` is empty for the last two: see `spent_at`.
 * Payments marked mistaken are left out. This replaces the retired daily pay
 * sheets, whose rows were converted into payments.
 *
 * The ticket-issue and trip-session links of a line are never joined, so a line
 * is one row; `payments` counts distinct payments because a payment has many lines.
 */
class PaymentLinesDataset extends ReportDataset
{
    public const KEY = 'maintenance.payment_lines';

    public function label(): string
    {
        return 'Payment lines';
    }

    public function grain(): array
    {
        return ['store', 'date'];
    }

    public function columns(): array
    {
        return [
            new Column('store', 'Store', 'string', 'stores.store_number', groupable: true),
            new Column('date', 'Paid date', 'date', 'payments.paid_at', groupable: true),
            new Column('technician', 'Technician', 'string', 'technicians.name', groupable: true),
            new Column('supplier', 'Supplier', 'string', 'suppliers.name', groupable: true),
            new Column(
                'method',
                'Method',
                'enum',
                'payments.method',
                options: array_map(fn (PaymentMethod $m) => ['value' => $m->value, 'label' => $m->label()], PaymentMethod::cases()),
                groupable: true,
            ),
            new Column(
                'type',
                'Line type',
                'enum',
                'payment_lines.type',
                options: array_map(fn (PaymentLineType $t) => ['value' => $t->value, 'label' => $t->label()], PaymentLineType::cases()),
                groupable: true,
            ),
            new Column(
                'spent_at',
                'Spent at',
                'enum',
                "CASE WHEN payment_lines.store_id IS NOT NULL THEN 'store' WHEN payment_lines.other_store IS NOT NULL THEN 'other_store' ELSE 'storage' END",
                options: [
                    ['value' => 'store', 'label' => 'A store'],
                    ['value' => 'other_store', 'label' => 'Another store'],
                    ['value' => 'storage', 'label' => 'Storage'],
                ],
                groupable: true,
            ),
            new Column('lines', 'Lines', 'integer', 'payment_lines.id', measure: true, aggregations: ['count']),
            new Column('payments', 'Payments', 'integer', 'payments.id', measure: true, aggregations: ['count_distinct']),
            new Column('hours', 'Hours', 'number', 'payment_lines.hours', measure: true, aggregations: ['sum', 'avg', 'max']),
            new Column('miles', 'Miles', 'number', 'payment_lines.miles', measure: true, aggregations: ['sum', 'avg', 'max']),
            new Column('amount', 'Amount', 'money', 'payment_lines.amount', measure: true, aggregations: ['sum', 'avg', 'min', 'max']),
        ];
    }

    public function baseQuery(): Builder
    {
        return DB::table('payment_lines')
            ->join('payments', 'payments.id', '=', 'payment_lines.payment_id')
            ->leftJoin('stores', 'stores.id', '=', 'payment_lines.store_id')
            ->leftJoin('technicians', 'technicians.id', '=', 'payments.technician_id')
            ->leftJoin('suppliers', 'suppliers.id', '=', 'payments.supplier_id')
            ->where('payments.mistaken', false);
    }

    public function storeColumn(): string
    {
        return 'payment_lines.store_id';
    }

    public function dateColumn(): string
    {
        return 'payments.paid_at';
    }
}
