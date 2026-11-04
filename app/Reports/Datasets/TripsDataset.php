<?php

declare(strict_types=1);

namespace App\Reports\Datasets;

use App\Enums\TripStatus;
use App\Reports\Contract\Column;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * A technician's clock, one row per trip session (a clock-in to clock-out) on
 * the local day he clocked in. Sessions do not split by store, so the stores
 * visited are `maintenance.trip_stops`. Mistaken sessions and sessions of
 * mistaken trips are left out.
 *
 * `clocked_minutes` is clock-in to clock-out, breaks included, and empty
 * while the session is open; the paid time (breaks taken off) is worked out
 * from the session's events in PHP, and what a payment actually paid is
 * `paid_minutes`. A session is claimed by at most one payment line (unique
 * index), so the payment joins never multiply a session.
 */
class TripsDataset extends GlobalDataset
{
    use LocalDates;

    public const KEY = 'maintenance.trips';

    public function label(): string
    {
        return 'Technician trips';
    }

    public function grain(): array
    {
        return ['date'];
    }

    public function columns(): array
    {
        return [
            new Column('date', 'Clock-in date', 'date', self::localDate('trip_sessions.clock_in'), groupable: true),
            new Column('technician', 'Technician', 'string', 'technicians.name', groupable: true),
            new Column(
                'trip_status',
                'Trip status',
                'enum',
                'trips.status',
                options: array_map(fn (TripStatus $s) => ['value' => $s->value, 'label' => $s->label()], TripStatus::cases()),
                groupable: true,
            ),
            new Column('source', 'Clocked by', 'string', 'trip_sessions.source', groupable: true),
            new Column('using_car', 'Used a car', 'boolean', 'trip_sessions.using_car', groupable: true),
            new Column(
                'payment',
                'Payment',
                'enum',
                "CASE WHEN trip_sessions.clock_out IS NULL THEN 'open' WHEN payments.id IS NULL THEN 'unpaid' ELSE 'paid' END",
                options: [
                    ['value' => 'open', 'label' => 'Still clocked in'],
                    ['value' => 'unpaid', 'label' => 'Unpaid'],
                    ['value' => 'paid', 'label' => 'Paid'],
                ],
                groupable: true,
            ),
            new Column('sessions', 'Sessions', 'integer', 'trip_sessions.id', measure: true, aggregations: ['count']),
            new Column('trips', 'Trips', 'integer', 'trips.id', measure: true, aggregations: ['count_distinct']),
            new Column(
                'clocked_minutes',
                'Clocked minutes (breaks included)',
                'number',
                self::minutesBetween('trip_sessions.clock_in', 'trip_sessions.clock_out'),
                measure: true,
                aggregations: ['sum', 'avg', 'max'],
            ),
            new Column(
                'paid_minutes',
                'Paid minutes',
                'integer',
                'CASE WHEN payments.id IS NULL THEN 0 ELSE payment_line_trip_session.paid_minutes END',
                measure: true,
                aggregations: ['sum', 'avg', 'max'],
            ),
        ];
    }

    public function baseQuery(): Builder
    {
        return DB::table('trip_sessions')
            ->join('trips', 'trips.id', '=', 'trip_sessions.trip_id')
            ->join('technicians', 'technicians.id', '=', 'trip_sessions.technician_id')
            ->leftJoin('payment_line_trip_session', 'payment_line_trip_session.trip_session_id', '=', 'trip_sessions.id')
            ->leftJoin('payment_lines', 'payment_lines.id', '=', 'payment_line_trip_session.payment_line_id')
            ->leftJoin('payments', fn ($join) => $join->on('payments.id', '=', 'payment_lines.payment_id')->where('payments.mistaken', false))
            ->where('trip_sessions.mistaken', false)
            ->where('trips.mistaken', false);
    }

    public function dateColumn(): string
    {
        return self::localDate('trip_sessions.clock_in');
    }
}
