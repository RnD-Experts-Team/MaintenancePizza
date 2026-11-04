<?php

namespace Tests\Feature\Reports;

use App\Reports\ReportDatasets;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\FakesAuthServer;
use Tests\TestCase;

/**
 * The datasets for tickets, pay, parts, storage and technician time, end to
 * end on in-memory sqlite. Each test builds the rows it needs and asks one
 * grouped question; the rows are chosen so that a join which multiplied a row
 * (an issue pivot, a payment's lines, a count sheet's lines) or a leaked
 * mistaken row would change the numbers.
 *
 * sqlite has neither CONVERT_TZ nor UNIX_TIMESTAMP, so setUp registers ones
 * that behave like MySQL's (with the time zone tables loaded, session in UTC).
 */
class MaintenanceDatasetsTest extends TestCase
{
    use FakesAuthServer;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $pdo = DB::connection()->getPdo();
        $pdo->sqliteCreateFunction(
            'CONVERT_TZ',
            fn (?string $value, string $from, string $to) => $value === null
                ? null
                : CarbonImmutable::parse($value, $from)->setTimezone($to)->format('Y-m-d H:i:s'),
            3,
        );
        $pdo->sqliteCreateFunction(
            'UNIX_TIMESTAMP',
            fn (?string $value) => $value === null ? null : (float) CarbonImmutable::parse($value, 'UTC')->getTimestamp(),
            1,
        );

        $this->fakeAuthServer();

        DB::table('stores')->insert([
            ['id' => 1, 'store_number' => '03795-00044'],
            ['id' => 2, 'store_number' => '03795-00042'],
        ]);
        DB::table('technicians')->insert([
            ['id' => 1, 'name' => 'Sam Rivers', 'phone' => '555-0100'],
            ['id' => 2, 'name' => 'Ana Costa', 'phone' => '555-0101'],
        ]);
        DB::table('suppliers')->insert(['id' => 1, 'name' => 'Walmart', 'name_key' => 'walmart']);
        DB::table('parts')->insert([
            ['id' => 1, 'name' => 'Thermostat', 'part_number' => 'T-1'],
            ['id' => 2, 'name' => 'Filter', 'part_number' => null],
            ['id' => 3, 'name' => 'Belt', 'part_number' => null],
        ]);
        DB::table('storage_locations')->insert([
            ['id' => 1, 'name' => 'Main shelf'],
            ['id' => 2, 'name' => 'Van'],
        ]);
    }

    private function url(string $key): string
    {
        return "/api/report-datasets/{$key}/query";
    }

    /** @return array{columns: list<array{key: string, type: string}>, rows: list<list<mixed>>} */
    private function ask(string $key, array $body): array
    {
        $body += ['stores' => [], 'date_from' => '2026-10-21', 'date_to' => '2026-10-27'];
        $response = $this->postJson($this->url($key), $body, $this->headers())->assertOk();

        return $response->json();
    }

    private function ticket(int $id, ?int $store, string $createdAt, array $statuses, string $type = 'normal', ?string $deletedAt = null): void
    {
        DB::table('tickets')->insert(['id' => $id, 'store_id' => $store, 'type' => $type, 'created_at' => $createdAt, 'deleted_at' => $deletedAt]);
        foreach ($statuses as $i => $status) {
            [$status, $priority] = is_array($status) ? $status : [$status, 'low'];
            DB::table('ticket_issues')->insert([
                'id' => $id * 10 + $i, 'ticket_id' => $id, 'other_title' => 'Leak', 'priority' => $priority,
                'description' => 'private words', 'status' => $status, 'created_at' => $createdAt,
            ]);
        }
    }

    public function test_the_catalog_lists_the_new_datasets_with_their_grain_and_dated_flag(): void
    {
        $catalog = collect($this->getJson('/api/report-datasets', $this->headers())->assertOk()->json('datasets'))->keyBy('key');

        $this->assertSame(array_map(fn (string $d) => $d::KEY, ReportDatasets::DATASETS), $catalog->keys()->all());
        $this->assertSame(
            [
                'maintenance.ticket_issues' => [['store', 'date'], true],
                'maintenance.assignments' => [['store', 'date'], true],
                'maintenance.tickets' => [['store', 'date'], true],
                'maintenance.payment_lines' => [['store', 'date'], true],
                'maintenance.payments' => [['date'], true],
                'maintenance.technician_purchases' => [['store', 'date'], true],
                'maintenance.stock_movements' => [['store', 'date'], true],
                'maintenance.stock_balances' => [[], false],
                'maintenance.stock_counts' => [['date'], true],
                'maintenance.trips' => [['date'], true],
                'maintenance.trip_stops' => [['store', 'date'], true],
            ],
            $catalog->map(fn (array $d) => [$d['grain'], $d['dated']])->all(),
        );

        // A grain key is a column of the dataset, and a global dataset offers no store column.
        foreach ($catalog as $dataset) {
            $keys = array_column($dataset['columns'], 'key');
            foreach ($dataset['grain'] as $grain) {
                $this->assertContains($grain, $keys, $dataset['key']);
            }
            if (! in_array('store', $dataset['grain'], true)) {
                $this->assertNotContains('store', $keys, $dataset['key']);
            }
        }

        $status = collect($catalog['maintenance.tickets']['columns'])->firstWhere('key', 'status');
        $this->assertContains(['value' => 'waiting_on_store', 'label' => 'Waiting on store'], $status['options']);
        $this->assertStringNotContainsString('CONVERT_TZ', json_encode($catalog->all()));
    }

    public function test_tickets_derive_their_status_and_count_each_ticket_once(): void
    {
        $this->ticket(1, 1, '2026-10-21 14:00:00', [['new', 'urgent'], ['closed', 'low']]);
        $this->ticket(2, 1, '2026-10-21 15:00:00', ['closed', 'cancelled'], 'preventive_maintenance');
        $this->ticket(3, 2, '2026-10-22 12:00:00', ['cancelled']);
        $this->ticket(4, 1, '2026-10-21 12:00:00', ['new'], 'normal', '2026-10-23 10:00:00');
        $this->ticket(5, 1, '2026-10-21 16:00:00', []);
        // 02:30 UTC on the 22nd is the 21st in New York.
        $this->ticket(6, 1, '2026-10-22 02:30:00', ['scheduled', ['waiting_on_store', 'high']]);
        // A ticket with no store, and one outside the period.
        $this->ticket(7, null, '2026-10-21 12:00:00', ['new']);
        $this->ticket(8, 1, '2026-10-30 12:00:00', ['new']);

        $result = $this->ask('maintenance.tickets', [
            'group_by' => ['status', 'closed'],
            'aggregates' => [
                ['column' => 'tickets', 'fn' => 'count', 'alias' => 'tickets'],
                ['column' => 'issues', 'fn' => 'sum', 'alias' => 'issues'],
            ],
        ]);

        $this->assertSame([
            ['key' => 'status', 'type' => 'enum'],
            ['key' => 'closed', 'type' => 'boolean'],
            ['key' => 'tickets', 'type' => 'integer'],
            ['key' => 'issues', 'type' => 'integer'],
        ], $result['columns']);
        // Ticket 1 has two issues and is one ticket, in "new" (new wins over closed); ticket 5 has none.
        $this->assertSame([
            ['cancelled', false, 1, 1],
            ['closed', true, 1, 2],
            ['new', false, 2, 2],
            ['waiting_on_store', false, 1, 2],
        ], $result['rows']);

        $byType = $this->ask('maintenance.tickets', [
            'stores' => ['03795-00044'],
            'columns' => ['store', 'date', 'type', 'priority'],
            'aggregates' => [['column' => 'tickets', 'fn' => 'count', 'alias' => 'tickets']],
        ]);
        $this->assertSame([
            ['03795-00044', '2026-10-21', 'normal', null, 1],
            ['03795-00044', '2026-10-21', 'normal', 'high', 1],
            ['03795-00044', '2026-10-21', 'normal', 'urgent', 1],
            ['03795-00044', '2026-10-21', 'preventive_maintenance', 'low', 1],
        ], $byType['rows']);
    }

    public function test_payment_lines_count_a_payment_once_and_leave_out_mistaken_ones(): void
    {
        $this->ticket(1, 1, '2026-10-21 14:00:00', ['new', 'new']);
        DB::table('payments')->insert([
            ['id' => 1, 'paid_at' => '2026-10-21', 'technician_id' => 1, 'supplier_id' => null, 'method' => 'cash', 'total' => 150, 'mistaken' => false],
            ['id' => 2, 'paid_at' => '2026-10-22', 'technician_id' => null, 'supplier_id' => 1, 'method' => 'check', 'total' => 70, 'mistaken' => false],
            ['id' => 3, 'paid_at' => '2026-10-21', 'technician_id' => 1, 'supplier_id' => null, 'method' => 'cash', 'total' => 999, 'mistaken' => true],
            ['id' => 4, 'paid_at' => '2026-10-30', 'technician_id' => 1, 'supplier_id' => null, 'method' => 'cash', 'total' => 5, 'mistaken' => false],
        ]);
        DB::table('payment_lines')->insert([
            ['id' => 1, 'payment_id' => 1, 'type' => 'hourly', 'store_id' => 1, 'storage_location_id' => null, 'hours' => 3, 'amount' => 100],
            ['id' => 2, 'payment_id' => 1, 'type' => 'parts', 'store_id' => 1, 'storage_location_id' => null, 'hours' => null, 'amount' => 50],
            ['id' => 3, 'payment_id' => 2, 'type' => 'parts', 'store_id' => null, 'storage_location_id' => 1, 'hours' => null, 'amount' => 70],
            ['id' => 4, 'payment_id' => 3, 'type' => 'fixed', 'store_id' => 1, 'storage_location_id' => null, 'hours' => null, 'amount' => 999],
            ['id' => 5, 'payment_id' => 4, 'type' => 'fixed', 'store_id' => 1, 'storage_location_id' => null, 'hours' => null, 'amount' => 5],
        ]);
        // Line 1 pays two issues: that must not double its money.
        DB::table('payment_line_ticket_issue')->insert([
            ['payment_line_id' => 1, 'ticket_issue_id' => 10],
            ['payment_line_id' => 1, 'ticket_issue_id' => 11],
        ]);

        $body = [
            'group_by' => ['store', 'spent_at'],
            'aggregates' => [
                ['column' => 'amount', 'fn' => 'sum', 'alias' => 'amount'],
                ['column' => 'payments', 'fn' => 'count_distinct', 'alias' => 'payments'],
                ['column' => 'lines', 'fn' => 'count', 'alias' => 'lines'],
                ['column' => 'hours', 'fn' => 'sum', 'alias' => 'hours'],
            ],
        ];
        $this->assertEquals(
            [[null, 'storage', 70.0, 1, 1, null], ['03795-00044', 'store', 150.0, 1, 2, 3.0]],
            $this->ask('maintenance.payment_lines', $body)['rows'],
        );

        // Only lines at the stores asked for.
        $this->assertEquals(
            [['03795-00044', 'store', 150.0, 1, 2, 3.0]],
            $this->ask('maintenance.payment_lines', ['stores' => ['03795-00044']] + $body)['rows'],
        );
    }

    public function test_payments_are_global_so_a_store_list_does_not_narrow_them(): void
    {
        DB::table('payments')->insert([
            ['id' => 1, 'paid_at' => '2026-10-21', 'technician_id' => 1, 'supplier_id' => null, 'method' => 'cash', 'total' => 150, 'mistaken' => false],
            ['id' => 2, 'paid_at' => '2026-10-22', 'technician_id' => null, 'supplier_id' => 1, 'method' => 'check', 'total' => 70, 'mistaken' => false],
            ['id' => 3, 'paid_at' => '2026-10-22', 'technician_id' => 2, 'supplier_id' => null, 'method' => null, 'total' => 30, 'mistaken' => false],
            ['id' => 4, 'paid_at' => '2026-10-21', 'technician_id' => 1, 'supplier_id' => null, 'method' => 'cash', 'total' => 999, 'mistaken' => true],
        ]);

        $body = [
            'group_by' => ['payee', 'method'],
            'aggregates' => [['column' => 'total', 'fn' => 'sum', 'alias' => 'total'], ['column' => 'payments', 'fn' => 'count', 'alias' => 'payments']],
        ];
        $expected = [['supplier', 'check', 70.0, 1], ['technician', null, 30.0, 1], ['technician', 'cash', 150.0, 1]];

        $this->assertEquals($expected, $this->ask('maintenance.payments', $body)['rows']);
        $this->assertEquals($expected, $this->ask('maintenance.payments', ['stores' => ['03795-00042']] + $body)['rows']);
    }

    public function test_technician_purchases_reach_a_store_through_their_issue_and_skip_mistaken_ones(): void
    {
        $this->ticket(1, 1, '2026-10-21 14:00:00', ['new', 'new']);
        DB::table('payments')->insert(['id' => 1, 'paid_at' => '2026-10-23', 'technician_id' => 1, 'total' => 20, 'mistaken' => false]);
        DB::table('payment_lines')->insert(['id' => 1, 'payment_id' => 1, 'type' => 'parts', 'amount' => 20]);
        DB::table('technician_purchases')->insert([
            ['id' => 1, 'technician_id' => 1, 'ticket_issue_id' => 10, 'part_id' => 1, 'description' => 'thermostat from the shop', 'quantity' => 2, 'total_cost' => 40, 'supplier_id' => 1, 'bought_at' => '2026-10-21 18:00:00', 'payment_line_id' => null, 'mistaken' => false],
            // 02:30 UTC on the 22nd is the 21st in New York; bought for the shelf, no store.
            ['id' => 2, 'technician_id' => 1, 'ticket_issue_id' => null, 'part_id' => null, 'description' => 'Fuses', 'quantity' => 10, 'total_cost' => 20, 'supplier_id' => null, 'bought_at' => '2026-10-22 02:30:00', 'payment_line_id' => 1, 'mistaken' => false],
            ['id' => 3, 'technician_id' => 1, 'ticket_issue_id' => 10, 'part_id' => 1, 'description' => 'oops', 'quantity' => 1, 'total_cost' => 999, 'supplier_id' => null, 'bought_at' => '2026-10-21 18:00:00', 'payment_line_id' => null, 'mistaken' => true],
        ]);

        $body = [
            'group_by' => ['store', 'date', 'part', 'payback'],
            'aggregates' => [
                ['column' => 'total_cost', 'fn' => 'sum', 'alias' => 'cost'],
                ['column' => 'quantity', 'fn' => 'sum', 'alias' => 'quantity'],
                ['column' => 'purchases', 'fn' => 'count', 'alias' => 'purchases'],
            ],
        ];

        $this->assertEquals([
            [null, '2026-10-21', 'Fuses', 'paid_back', 20.0, 10.0, 1],
            ['03795-00044', '2026-10-21', 'Thermostat', 'waiting', 40.0, 2.0, 1],
        ], $this->ask('maintenance.technician_purchases', $body)['rows']);
        $this->assertEquals([
            ['03795-00044', '2026-10-21', 'Thermostat', 'waiting', 40.0, 2.0, 1],
        ], $this->ask('maintenance.technician_purchases', ['stores' => ['03795-00044']] + $body)['rows']);
    }

    public function test_stock_movements_net_to_the_ledger_and_leave_out_mistaken_ones_with_their_undo(): void
    {
        $this->ticket(1, 1, '2026-10-21 14:00:00', ['new']);
        $movement = fn (int $id, string $type, string $at, array $extra = []) => DB::table('stock_movements')->insert(
            $extra + ['id' => $id, 'type' => $type, 'moved_at' => $at, 'mistaken' => false],
        );
        $line = fn (int $movementId, int $part, int $location, float $qty, int $direction, ?float $cost) => DB::table('stock_movement_lines')->insert([
            'stock_movement_id' => $movementId, 'part_id' => $part, 'storage_location_id' => $location,
            'quantity' => $qty, 'direction' => $direction, 'total_cost' => $cost,
        ]);

        $movement(1, 'bought', '2026-10-21 12:00:00');
        $line(1, 1, 1, 10, 1, 100);
        $movement(2, 'used', '2026-10-22 15:00:00', ['ticket_issue_id' => 10]);
        $line(2, 1, 1, 3, -1, 30);
        // A move is one movement with an outbound and an inbound line.
        $movement(3, 'moved', '2026-10-23 09:00:00');
        $line(3, 1, 1, 2, -1, 20);
        $line(3, 1, 2, 2, 1, 20);
        // Marked mistaken, and the movement that undid it: both are out, so they cannot skew the net.
        $movement(4, 'damaged_lost', '2026-10-23 10:00:00', ['mistaken' => true]);
        $line(4, 1, 1, 1, -1, 10);
        $movement(5, 'undone', '2026-10-23 11:00:00', ['reverses_stock_movement_id' => 4]);
        $line(5, 1, 1, 1, 1, 10);
        // Outside the period.
        $movement(6, 'bought', '2026-11-20 12:00:00');
        $line(6, 1, 1, 50, 1, 500);

        $this->assertEquals(
            [['Thermostat', 4, 3, 7.0, 70.0]],
            $this->ask('maintenance.stock_movements', [
                'group_by' => ['part'],
                'aggregates' => [
                    ['column' => 'lines', 'fn' => 'count', 'alias' => 'lines'],
                    ['column' => 'movements', 'fn' => 'count_distinct', 'alias' => 'movements'],
                    ['column' => 'net_quantity', 'fn' => 'sum', 'alias' => 'net'],
                    ['column' => 'net_value', 'fn' => 'sum', 'alias' => 'net_value'],
                ],
            ])['rows'],
        );

        $this->assertEquals(
            [['bought', 'in', 10.0], ['moved', 'in', 2.0], ['moved', 'out', 2.0], ['used', 'out', 3.0]],
            $this->ask('maintenance.stock_movements', [
                'group_by' => ['type', 'direction'],
                'aggregates' => [['column' => 'quantity', 'fn' => 'sum', 'alias' => 'quantity']],
            ])['rows'],
        );

        // Only the movement that belongs to a ticket issue has a store.
        $this->assertEquals(
            [['03795-00044', '2026-10-22', 'used', 3.0]],
            $this->ask('maintenance.stock_movements', [
                'stores' => ['03795-00044'],
                'group_by' => ['store', 'date', 'type'],
                'aggregates' => [['column' => 'quantity', 'fn' => 'sum', 'alias' => 'quantity']],
            ])['rows'],
        );
    }

    public function test_the_stock_balance_snapshot_ignores_the_period(): void
    {
        DB::table('stock_balances')->insert([
            ['part_id' => 1, 'storage_location_id' => 1, 'quantity' => 5],
            ['part_id' => 1, 'storage_location_id' => 2, 'quantity' => 2],
            ['part_id' => 2, 'storage_location_id' => 1, 'quantity' => -1],
            ['part_id' => 3, 'storage_location_id' => 1, 'quantity' => 0],
        ]);
        $body = [
            'group_by' => ['part', 'state'],
            'aggregates' => [['column' => 'on_hand', 'fn' => 'sum', 'alias' => 'on_hand'], ['column' => 'balances', 'fn' => 'count', 'alias' => 'pairs']],
        ];
        $expected = [['Belt', 'none', 0.0, 1], ['Filter', 'negative', -1.0, 1], ['Thermostat', 'in_stock', 7.0, 2]];

        $this->assertEquals($expected, $this->ask('maintenance.stock_balances', $body)['rows']);
        // Any period, a period in 1999, and no period at all give the same stock; a store list does not narrow it.
        $this->assertEquals($expected, $this->ask('maintenance.stock_balances', ['date_from' => '1999-01-01', 'date_to' => '1999-01-02'] + $body)['rows']);
        $this->assertEquals($expected, $this->ask('maintenance.stock_balances', ['stores' => ['03795-00042']] + $body)['rows']);

        $withoutPeriod = $this->postJson($this->url('maintenance.stock_balances'), ['stores' => []] + $body, $this->headers())->assertOk();
        $this->assertEquals($expected, $withoutPeriod->json('rows'));

        $this->assertEquals([['Main shelf', 5.0]], $this->ask('maintenance.stock_balances', [
            'group_by' => ['location'],
            'aggregates' => [['column' => 'on_hand', 'fn' => 'max', 'alias' => 'biggest']],
            'filters' => [['column' => 'location', 'op' => 'eq', 'value' => 'Main shelf']],
        ])['rows']);
    }

    public function test_stock_counts_sum_their_lines_without_multiplying_the_sheet_values(): void
    {
        DB::table('stock_counts')->insert([
            ['id' => 1, 'storage_location_id' => 1, 'status' => 'applied', 'started_at' => '2026-10-21 10:00:00', 'applied_at' => '2026-10-21 11:00:00', 'missing_value' => 12.50, 'extra_value' => 3.00],
            ['id' => 2, 'storage_location_id' => 2, 'status' => 'draft', 'started_at' => '2026-10-22 10:00:00', 'applied_at' => null, 'missing_value' => 0, 'extra_value' => 0],
            ['id' => 3, 'storage_location_id' => 2, 'status' => 'draft', 'started_at' => '2026-12-22 10:00:00', 'applied_at' => null, 'missing_value' => 0, 'extra_value' => 0],
        ]);
        DB::table('stock_count_lines')->insert([
            ['stock_count_id' => 1, 'part_id' => 1, 'expected' => 5, 'counted' => 4],
            ['stock_count_id' => 1, 'part_id' => 2, 'expected' => 2, 'counted' => 5],
            ['stock_count_id' => 1, 'part_id' => 3, 'expected' => 1, 'counted' => null],
            ['stock_count_id' => 2, 'part_id' => 1, 'expected' => 1, 'counted' => 1],
        ]);

        $this->assertEquals(
            [['applied', 1, 3, 2, 2.0, 12.5, 3.0], ['draft', 1, 1, 1, 0.0, 0.0, 0.0]],
            $this->ask('maintenance.stock_counts', [
                'group_by' => ['status'],
                'aggregates' => [
                    ['column' => 'counts', 'fn' => 'count', 'alias' => 'counts'],
                    ['column' => 'lines', 'fn' => 'sum', 'alias' => 'lines'],
                    ['column' => 'counted_lines', 'fn' => 'sum', 'alias' => 'counted'],
                    ['column' => 'variance', 'fn' => 'sum', 'alias' => 'variance'],
                    ['column' => 'missing_value', 'fn' => 'sum', 'alias' => 'missing'],
                    ['column' => 'extra_value', 'fn' => 'sum', 'alias' => 'extra'],
                ],
            ])['rows'],
        );

        $this->assertEquals(
            [['2026-10-21', 'Main shelf'], ['2026-10-22', 'Van']],
            $this->ask('maintenance.stock_counts', ['columns' => ['date', 'location']])['rows'],
        );
    }

    public function test_trips_sum_each_session_once_and_leave_out_mistaken_ones(): void
    {
        DB::table('trips')->insert([
            ['id' => 1, 'technician_id' => 1, 'status' => 'open', 'planned_date' => '2026-10-21', 'mistaken' => false],
            ['id' => 2, 'technician_id' => 2, 'status' => 'open', 'planned_date' => '2026-10-21', 'mistaken' => true],
        ]);
        $session = fn (int $id, int $trip, int $tech, string $in, ?string $out, array $extra = []) => DB::table('trip_sessions')->insert([
            'id' => $id, 'trip_id' => $trip, 'technician_id' => $tech, 'clock_in' => $in, 'clock_out' => $out,
        ] + $extra + [
            'open_marker' => $out === null ? true : null, 'using_car' => false, 'source' => 'technician', 'mistaken' => false,
        ]);
        $session(1, 1, 1, '2026-10-21 13:00:00', '2026-10-21 15:30:00');
        // 02:30 UTC on the 22nd is the 21st in New York.
        $session(2, 1, 1, '2026-10-22 02:30:00', '2026-10-22 03:30:00', ['using_car' => true]);
        $session(3, 1, 1, '2026-10-21 20:00:00', null);
        $session(4, 1, 1, '2026-10-21 08:00:00', '2026-10-21 09:00:00', ['mistaken' => true]);
        $session(5, 2, 2, '2026-10-21 08:00:00', '2026-10-21 09:00:00');

        DB::table('payments')->insert([
            ['id' => 1, 'paid_at' => '2026-10-25', 'technician_id' => 1, 'total' => 100, 'mistaken' => false],
            ['id' => 2, 'paid_at' => '2026-10-25', 'technician_id' => 1, 'total' => 100, 'mistaken' => true],
        ]);
        DB::table('payment_lines')->insert([
            ['id' => 1, 'payment_id' => 1, 'type' => 'hourly', 'amount' => 100],
            ['id' => 2, 'payment_id' => 2, 'type' => 'hourly', 'amount' => 100],
        ]);
        // Session 1 is paid (140 of its 150 minutes: a break is not paid); session 2's only claim is on a mistaken payment.
        DB::table('payment_line_trip_session')->insert([
            ['payment_line_id' => 1, 'trip_session_id' => 1, 'paid_minutes' => 140],
            ['payment_line_id' => 2, 'trip_session_id' => 2, 'paid_minutes' => 60],
        ]);

        $this->assertEquals(
            [['open', 1, null, 0], ['paid', 1, 150.0, 140], ['unpaid', 1, 60.0, 0]],
            $this->ask('maintenance.trips', [
                'group_by' => ['payment'],
                'aggregates' => [
                    ['column' => 'sessions', 'fn' => 'count', 'alias' => 'sessions'],
                    ['column' => 'clocked_minutes', 'fn' => 'sum', 'alias' => 'clocked'],
                    ['column' => 'paid_minutes', 'fn' => 'sum', 'alias' => 'paid'],
                ],
            ])['rows'],
        );

        $this->assertEquals(
            [['2026-10-21', 'Sam Rivers', 3, 1]],
            $this->ask('maintenance.trips', [
                'group_by' => ['date', 'technician'],
                'aggregates' => [
                    ['column' => 'sessions', 'fn' => 'count', 'alias' => 'sessions'],
                    ['column' => 'trips', 'fn' => 'count_distinct', 'alias' => 'trips'],
                ],
            ])['rows'],
        );
    }

    public function test_trip_stops_count_each_stop_once_by_store_and_skip_mistaken_ones(): void
    {
        $this->ticket(1, 1, '2026-10-21 14:00:00', ['new', 'new']);
        DB::table('trips')->insert([
            ['id' => 1, 'technician_id' => 1, 'status' => 'open', 'planned_date' => '2026-10-21', 'mistaken' => false],
            ['id' => 2, 'technician_id' => 2, 'status' => 'open', 'planned_date' => '2026-10-21', 'mistaken' => true],
        ]);
        DB::table('trip_stops')->insert([
            ['id' => 1, 'trip_id' => 1, 'store_id' => 1, 'other_store' => null, 'status' => 'done', 'arrived_at' => '2026-10-21 14:00:00', 'left_at' => '2026-10-21 15:00:00', 'mistaken' => false],
            // Planned only: counted on the day the trip was planned for.
            ['id' => 2, 'trip_id' => 1, 'store_id' => 1, 'other_store' => null, 'status' => 'planned', 'arrived_at' => null, 'left_at' => null, 'mistaken' => false],
            ['id' => 3, 'trip_id' => 1, 'store_id' => null, 'other_store' => 'Cafe', 'status' => 'done', 'arrived_at' => '2026-10-21 16:00:00', 'left_at' => '2026-10-21 16:30:00', 'mistaken' => false],
            ['id' => 4, 'trip_id' => 1, 'store_id' => 1, 'other_store' => null, 'status' => 'done', 'arrived_at' => '2026-10-21 17:00:00', 'left_at' => '2026-10-21 17:30:00', 'mistaken' => true],
            ['id' => 5, 'trip_id' => 1, 'store_id' => 2, 'other_store' => null, 'status' => 'done', 'arrived_at' => '2026-10-22 14:00:00', 'left_at' => '2026-10-22 14:45:00', 'mistaken' => false],
            ['id' => 6, 'trip_id' => 2, 'store_id' => 2, 'other_store' => null, 'status' => 'done', 'arrived_at' => '2026-10-22 14:00:00', 'left_at' => '2026-10-22 14:45:00', 'mistaken' => false],
        ]);
        // Stop 1 is for two issues: that must not double the stop or its minutes.
        DB::table('trip_stop_issues')->insert([
            ['trip_stop_id' => 1, 'ticket_issue_id' => 10],
            ['trip_stop_id' => 1, 'ticket_issue_id' => 11],
        ]);

        $body = [
            'group_by' => ['store', 'technician'],
            'aggregates' => [
                ['column' => 'stops', 'fn' => 'count', 'alias' => 'stops'],
                ['column' => 'minutes_on_site', 'fn' => 'sum', 'alias' => 'minutes'],
            ],
        ];
        $this->assertEquals(
            [['03795-00042', 'Sam Rivers', 1, 45.0], ['03795-00044', 'Sam Rivers', 2, 60.0]],
            $this->ask('maintenance.trip_stops', $body)['rows'],
        );
        $this->assertEquals(
            [['03795-00044', 'Sam Rivers', 2, 60.0]],
            $this->ask('maintenance.trip_stops', ['stores' => ['03795-00044']] + $body)['rows'],
        );
    }
}
