<?php

declare(strict_types=1);

namespace App\Reports\Datasets;

use App\Enums\IssueStatus;
use App\Enums\Priority;
use App\Enums\TicketStatus;
use App\Enums\TicketType;
use App\Reports\Contract\Column;
use App\Reports\Contract\ReportDataset;
use App\Services\TicketStatusService;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * One row per ticket (a store's visit-sized bundle of issues), on the local day
 * it was filed. Tickets in a store only; deleted tickets are left out.
 *
 * A ticket has no stored status: it is derived from its issues, in the order of
 * TicketStatusService::PRECEDENCE. The per-ticket figures below (issue count,
 * best status rank, highest priority) come from ONE grouped subquery over the
 * issues, so joining them never multiplies a ticket. `priority` is the highest
 * of the ticket's issues.
 */
class TicketsDataset extends ReportDataset
{
    use LocalDates;

    public const KEY = 'maintenance.tickets';

    public function label(): string
    {
        return 'Tickets';
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
        $status = $this->statusSql();

        return [
            new Column('store', 'Store', 'string', 'stores.store_number', groupable: true),
            new Column('date', 'Created date', 'date', self::localDate('tickets.created_at'), groupable: true),
            new Column(
                'type',
                'Type',
                'enum',
                'tickets.type',
                options: array_map(fn (TicketType $t) => ['value' => $t->value, 'label' => $t->label()], TicketType::cases()),
                groupable: true,
            ),
            new Column(
                'status',
                'Status',
                'enum',
                $status,
                options: array_map(fn (TicketStatus $s) => ['value' => $s->value, 'label' => $s->label()], TicketStatus::cases()),
                groupable: true,
            ),
            new Column(
                'priority',
                'Highest priority',
                'enum',
                $this->prioritySql(),
                options: array_map(fn (Priority $p) => ['value' => $p->value, 'label' => $p->label()], Priority::cases()),
                groupable: true,
            ),
            new Column('closed', 'Closed', 'boolean', "CASE WHEN {$status} = 'closed' THEN 1 ELSE 0 END", groupable: true),
            new Column('tickets', 'Tickets', 'integer', 'tickets.id', measure: true, aggregations: ['count']),
            new Column('issues', 'Issues', 'integer', 'COALESCE(ticket_totals.issues, 0)', measure: true, aggregations: ['sum', 'avg', 'max']),
        ];
    }

    public function baseQuery(): Builder
    {
        $totals = DB::table('ticket_issues')
            ->selectRaw('ticket_id, COUNT(*) as issues, MIN('.$this->statusRankSql().') as status_rank, '
                .'MAX(CASE WHEN status <> \''.IssueStatus::Cancelled->value.'\' THEN 1 ELSE 0 END) as live, '
                .'MAX('.$this->priorityWeightSql().') as priority_weight')
            ->groupBy('ticket_id');

        return DB::table('tickets')
            ->join('stores', 'stores.id', '=', 'tickets.store_id')
            ->leftJoinSub($totals, 'ticket_totals', 'ticket_totals.ticket_id', '=', 'tickets.id')
            ->whereNull('tickets.deleted_at');
    }

    public function storeColumn(): string
    {
        return 'tickets.store_id';
    }

    public function dateColumn(): string
    {
        return self::localDate('tickets.created_at');
    }

    /** The open statuses win in precedence order (rank 1 first); closed and cancelled share the last rank. */
    private function statusRankSql(): string
    {
        $when = '';
        foreach (TicketStatusService::PRECEDENCE as $rank => $status) {
            $when .= " WHEN '{$status->value}' THEN ".($rank + 1);
        }

        return 'CASE status'.$when.' ELSE '.(count(TicketStatusService::PRECEDENCE) + 1).' END';
    }

    /** The SQL twin of TicketStatusService::for(). */
    private function statusSql(): string
    {
        $sql = 'CASE WHEN ticket_totals.issues IS NULL THEN \''.TicketStatus::New->value.'\'';
        foreach (TicketStatusService::PRECEDENCE as $rank => $status) {
            $sql .= ' WHEN ticket_totals.status_rank = '.($rank + 1)." THEN '{$status->value}'";
        }

        return $sql.' WHEN ticket_totals.live = 0 THEN \''.TicketStatus::Cancelled->value.'\' ELSE \''.TicketStatus::Closed->value.'\' END';
    }

    private function priorityWeightSql(): string
    {
        $when = '';
        foreach (Priority::cases() as $priority) {
            $when .= " WHEN '{$priority->value}' THEN {$priority->weight()}";
        }

        return 'CASE priority'.$when.' ELSE 0 END';
    }

    private function prioritySql(): string
    {
        $when = '';
        foreach (Priority::cases() as $priority) {
            $when .= " WHEN {$priority->weight()} THEN '{$priority->value}'";
        }

        return 'CASE ticket_totals.priority_weight'.$when.' END';
    }
}
