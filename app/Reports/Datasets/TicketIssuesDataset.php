<?php

declare(strict_types=1);

namespace App\Reports\Datasets;

use App\Enums\IssueStatus;
use App\Enums\Priority;
use App\Reports\Contract\Column;
use App\Reports\Contract\ReportDataset;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * One row per ticket issue (a ticket is a store's visit-sized bundle of
 * issues), on the local day it was filed. Tickets in a store only: an "other
 * store" ticket has no store code and is not here. Free-text descriptions,
 * contacts and notes are never offered.
 *
 * Timestamps are stored in UTC (config/app.php); the day is the New York one.
 * CONVERT_TZ needs MySQL's time zone tables loaded, or every date is NULL.
 */
class TicketIssuesDataset extends ReportDataset
{
    public const KEY = 'maintenance.ticket_issues';

    private const LOCAL_DATE = "DATE(CONVERT_TZ(ticket_issues.created_at, 'UTC', 'America/New_York'))";

    public function label(): string
    {
        return 'Ticket issues';
    }

    public function grain(): array
    {
        return ['store', 'date'];
    }

    public function columns(): array
    {
        return [
            new Column('store', 'Store', 'string', 'stores.store_number', groupable: true),
            new Column('date', 'Created date', 'date', self::LOCAL_DATE, groupable: true),
            new Column(
                'status',
                'Status',
                'enum',
                'ticket_issues.status',
                options: array_map(fn (IssueStatus $s) => ['value' => $s->value, 'label' => $s->label()], IssueStatus::cases()),
                groupable: true,
            ),
            new Column(
                'priority',
                'Priority',
                'enum',
                'ticket_issues.priority',
                options: array_map(fn (Priority $p) => ['value' => $p->value, 'label' => $p->label()], Priority::cases()),
                groupable: true,
            ),
            new Column('issue', 'Issue', 'string', 'COALESCE(issues.title, ticket_issues.other_title)', groupable: true),
            new Column('issues', 'Issues', 'integer', 'ticket_issues.id', measure: true, aggregations: ['count']),
        ];
    }

    public function baseQuery(): Builder
    {
        return DB::table('ticket_issues')
            ->join('tickets', 'tickets.id', '=', 'ticket_issues.ticket_id')
            ->join('stores', 'stores.id', '=', 'tickets.store_id')
            ->leftJoin('issues', 'issues.id', '=', 'ticket_issues.issue_id')
            ->whereNull('tickets.deleted_at');
    }

    public function storeColumn(): string
    {
        return 'tickets.store_id';
    }

    public function dateColumn(): string
    {
        return self::LOCAL_DATE;
    }
}
