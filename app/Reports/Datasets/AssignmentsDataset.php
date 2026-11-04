<?php

declare(strict_types=1);

namespace App\Reports\Datasets;

use App\Models\Assignment;
use App\Reports\Contract\Column;
use App\Reports\Contract\ReportDataset;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Bookings (work visits and inspections) on the day they are booked for. A
 * booking reaches a store through the issues it covers, so the query joins the
 * issue pivot and one booking can appear once per issue: count the
 * `assignments` measure with count_distinct. Mistaken bookings are left out.
 * Only the technician's name is offered, never the phone number.
 */
class AssignmentsDataset extends ReportDataset
{
    public const KEY = 'maintenance.assignments';

    public function label(): string
    {
        return 'Technician bookings';
    }

    public function grain(): array
    {
        return ['store', 'date'];
    }

    public function heavy(): bool
    {
        return true;
    }

    public function columns(): array
    {
        return [
            new Column('store', 'Store', 'string', 'stores.store_number', groupable: true),
            new Column('date', 'Booked date', 'date', 'assignments.assigned_date', groupable: true),
            new Column(
                'kind',
                'Kind',
                'enum',
                'assignments.kind',
                options: array_map(fn (string $k) => ['value' => $k, 'label' => ucfirst($k)], Assignment::KINDS),
                groupable: true,
            ),
            new Column('technician', 'Technician', 'string', 'technicians.name', groupable: true),
            new Column(
                'status',
                'Status',
                'enum',
                "CASE WHEN assignments.ended_at IS NULL THEN 'standing' ELSE 'ended' END",
                options: [['value' => 'standing', 'label' => 'Standing'], ['value' => 'ended', 'label' => 'Ended before the day']],
                groupable: true,
            ),
            new Column('assignments', 'Bookings', 'integer', 'assignments.id', measure: true, aggregations: ['count_distinct']),
        ];
    }

    public function baseQuery(): Builder
    {
        return DB::table('assignments')
            ->join('assignment_ticket_issue', 'assignment_ticket_issue.assignment_id', '=', 'assignments.id')
            ->join('ticket_issues', 'ticket_issues.id', '=', 'assignment_ticket_issue.ticket_issue_id')
            ->join('tickets', 'tickets.id', '=', 'ticket_issues.ticket_id')
            ->join('stores', 'stores.id', '=', 'tickets.store_id')
            ->leftJoin('technicians', 'technicians.id', '=', 'assignments.technician_id')
            ->where('assignments.mistaken', false)
            ->whereNull('tickets.deleted_at');
    }

    public function storeColumn(): string
    {
        return 'tickets.store_id';
    }

    public function dateColumn(): string
    {
        return 'assignments.assigned_date';
    }
}
