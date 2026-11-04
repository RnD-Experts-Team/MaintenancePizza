<?php

namespace Tests\Feature\Reports;

use App\Http\Requests\ReportDatasetQueryRequest;
use App\Reports\Contract\ReportDataset;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\FakesAuthServer;
use Tests\TestCase;

/**
 * The contract routes end to end, through the real auth middleware with
 * pizzasys faked, on in-memory sqlite. sqlite has no CONVERT_TZ, so the test
 * registers one that behaves like MySQL's with the time zone tables loaded.
 */
class ReportDatasetQueryTest extends TestCase
{
    use FakesAuthServer;
    use RefreshDatabase;

    private const ISSUES = '/api/report-datasets/maintenance.ticket_issues/query';

    private const BOOKINGS = '/api/report-datasets/maintenance.assignments/query';

    protected function setUp(): void
    {
        parent::setUp();

        DB::connection()->getPdo()->sqliteCreateFunction(
            'CONVERT_TZ',
            fn (?string $value, string $from, string $to) => $value === null
                ? null
                : CarbonImmutable::parse($value, $from)->setTimezone($to)->format('Y-m-d H:i:s'),
            3,
        );

        $this->fakeAuthServer();

        DB::table('stores')->insert([
            ['id' => 1, 'store_number' => '03795-00044'],
            ['id' => 2, 'store_number' => '03795-00042'],
        ]);
        DB::table('issues')->insert(['id' => 1, 'title' => 'Oven down']);
        DB::table('technicians')->insert(['id' => 1, 'name' => 'Sam Rivers', 'phone' => '555-0100']);

        $ticket = fn (int $id, ?int $store, ?string $other = null, ?string $deletedAt = null) => [
            'id' => $id, 'store_id' => $store, 'other_store' => $other, 'type' => 'normal', 'deleted_at' => $deletedAt,
        ];
        DB::table('tickets')->insert([
            $ticket(1, 1),
            $ticket(2, 2),
            $ticket(3, 1, null, '2026-10-23 10:00:00'),
            $ticket(4, null, 'Off-system'),
        ]);

        $issue = fn (int $id, int $ticket, ?int $catalog, string $status, string $priority, string $createdAt) => [
            'id' => $id, 'ticket_id' => $ticket, 'issue_id' => $catalog, 'other_title' => $catalog ? null : 'Leak',
            'priority' => $priority, 'description' => 'private words', 'status' => $status, 'created_at' => $createdAt,
        ];
        DB::table('ticket_issues')->insert([
            $issue(1, 1, 1, 'new', 'urgent', '2026-10-21 14:00:00'),
            $issue(2, 1, 1, 'new', 'urgent', '2026-10-21 18:00:00'),
            // 02:30 UTC on the 22nd is 22:30 on the 21st in New York.
            $issue(3, 1, null, 'closed', 'low', '2026-10-22 02:30:00'),
            $issue(4, 2, 1, 'new', 'high', '2026-10-21 12:00:00'),
            // Deleted ticket, and a ticket outside the store list: neither is reported.
            $issue(5, 3, 1, 'new', 'high', '2026-10-21 12:00:00'),
            $issue(6, 4, 1, 'new', 'high', '2026-10-21 12:00:00'),
            // Outside the period.
            $issue(7, 1, 1, 'new', 'high', '2026-10-30 12:00:00'),
        ]);

        // Booking 1 covers two issues of one ticket; 2 is mistaken; 3 is another store's and ended.
        DB::table('assignments')->insert([
            ['id' => 1, 'kind' => 'work', 'technician_id' => 1, 'assigned_date' => '2026-10-21', 'mistaken' => false, 'ended_at' => null],
            ['id' => 2, 'kind' => 'work', 'technician_id' => 1, 'assigned_date' => '2026-10-21', 'mistaken' => true, 'ended_at' => null],
            ['id' => 3, 'kind' => 'inspection', 'technician_id' => null, 'assigned_date' => '2026-10-22', 'mistaken' => false, 'ended_at' => '2026-10-21 09:00:00'],
        ]);
        DB::table('assignment_ticket_issue')->insert([
            ['assignment_id' => 1, 'ticket_issue_id' => 1],
            ['assignment_id' => 1, 'ticket_issue_id' => 2],
            ['assignment_id' => 2, 'ticket_issue_id' => 1],
            ['assignment_id' => 3, 'ticket_issue_id' => 4],
        ]);
    }

    private function body(array $overrides = []): array
    {
        return array_replace([
            'stores' => ['03795-00044'],
            'date_from' => '2026-10-21',
            'date_to' => '2026-10-27',
            'columns' => ['store', 'date', 'status'],
            'group_by' => ['store', 'date', 'status'],
            'aggregates' => [['column' => 'issues', 'fn' => 'count', 'alias' => 'issues']],
            'limit' => 50,
        ], $overrides);
    }

    public function test_it_returns_positional_rows_grouped_by_store_and_local_date(): void
    {
        $response = $this->postJson(self::ISSUES, $this->body(), $this->headers())
            ->assertOk()
            ->assertJson([
                'columns' => [
                    ['key' => 'store', 'type' => 'string'],
                    ['key' => 'date', 'type' => 'date'],
                    ['key' => 'status', 'type' => 'enum'],
                    ['key' => 'issues', 'type' => 'integer'],
                ],
                // Store 2 is filtered out; the 02:30 UTC issue of the 22nd is still the 21st in New York.
                'rows' => [['03795-00044', '2026-10-21', 'closed', 1], ['03795-00044', '2026-10-21', 'new', 2]],
                'next_cursor' => null,
                'truncated' => false,
                'meta' => ['row_count' => 2, 'contract_version' => 1],
            ]);
        $this->assertCount(2, $response->json('rows'));

        // pizzasys judged this exact route, with the store codes of the body.
        Http::assertSent(fn (Request $request) => $request['path'] === self::ISSUES
            && $request['method'] === 'POST'
            && $request['store_context']['body']['stores'] === ['03795-00044']
            && $request['store_context']['path']['dataset'] === 'maintenance.ticket_issues');
    }

    public function test_no_stores_means_every_store_with_a_code_but_not_deleted_tickets(): void
    {
        $this->postJson(self::ISSUES, $this->body(['stores' => [], 'columns' => [], 'group_by' => ['store']]), $this->headers())
            ->assertOk()
            ->assertJsonPath('rows', [['03795-00042', 1], ['03795-00044', 3]]);
    }

    public function test_the_issue_name_falls_back_to_the_free_text_title(): void
    {
        $this->postJson(self::ISSUES, $this->body([
            'columns' => ['issue', 'priority'],
            'group_by' => ['issue', 'priority'],
        ]), $this->headers())
            ->assertOk()
            ->assertJsonPath('rows', [['Leak', 'low', 1], ['Oven down', 'urgent', 2]]);
    }

    public function test_a_store_pizzasys_refuses_is_a_403(): void
    {
        $this->tokenAuthorized = false;

        $this->postJson(self::ISSUES, $this->body(['stores' => ['03795-00042']]), $this->headers())->assertForbidden();
    }

    public function test_unknown_columns_and_unoffered_aggregates_are_a_422(): void
    {
        $this->postJson(self::ISSUES, $this->body(['columns' => ['store', 'description']]), $this->headers())
            ->assertUnprocessable()
            ->assertJsonPath('errors.columns', ['Unknown column description.']);

        $this->postJson(self::ISSUES, $this->body(['aggregates' => [['column' => 'issues', 'fn' => 'sum']]]), $this->headers())
            ->assertUnprocessable()
            ->assertJsonPath('errors', ['aggregates.0.fn' => ['sum is not offered for issues.']]);

        $this->postJson(self::ISSUES, $this->body(['date_to' => '2026-10-01']), $this->headers())
            ->assertUnprocessable()
            ->assertJsonValidationErrors('date_to');
    }

    public function test_store_codes_must_have_the_code_format(): void
    {
        foreach (['3795', '0379500044', '03795-0004'] as $code) {
            $this->postJson(self::ISSUES, $this->body(['stores' => [$code]]), $this->headers())
                ->assertUnprocessable()
                ->assertJsonValidationErrors('stores.0');
        }

        $this->assertArrayNotHasKey('timeout_ms', (new ReportDatasetQueryRequest)->rules());
    }

    public function test_bookings_count_each_booking_once_and_leave_out_mistaken_ones(): void
    {
        $body = $this->body([
            'stores' => [],
            'columns' => ['store', 'date', 'kind', 'technician', 'status'],
            'group_by' => ['store', 'date', 'kind', 'technician', 'status'],
            'aggregates' => [['column' => 'assignments', 'fn' => 'count_distinct', 'alias' => 'bookings']],
        ]);

        // Booking 1 covers two issues but is one booking; 2 is mistaken; 3 has no technician and ended.
        $this->postJson(self::BOOKINGS, $body, $this->headers())
            ->assertOk()
            ->assertJsonPath('rows', [
                ['03795-00042', '2026-10-22', 'inspection', null, 'ended', 1],
                ['03795-00044', '2026-10-21', 'work', 'Sam Rivers', 'standing', 1],
            ]);

        $plainCount = $this->body(['columns' => ['store'], 'group_by' => ['store'], 'aggregates' => [['column' => 'assignments', 'fn' => 'count']]]);
        $this->postJson(self::BOOKINGS, $plainCount, $this->headers())
            ->assertUnprocessable()
            ->assertJsonPath('errors', ['aggregates.0.fn' => ['count is not offered for assignments.']]);
    }

    public function test_any_period_is_answered_but_a_dated_dataset_needs_one(): void
    {
        $body = $this->body(['date_from' => '2020-01-01', 'date_to' => '2026-12-31', 'columns' => ['store'], 'group_by' => ['store'], 'aggregates' => []]);

        $this->postJson(self::BOOKINGS, $body, $this->headers())
            ->assertOk()
            ->assertJsonPath('rows', [['03795-00044']]);

        unset($body['date_from'], $body['date_to']);
        $this->postJson(self::BOOKINGS, $body, $this->headers())
            ->assertUnprocessable()
            ->assertJsonValidationErrors('date_from');
    }

    public function test_the_catalog_names_the_service_and_lists_every_dataset(): void
    {
        $response = $this->getJson('/api/report-datasets', $this->headers())
            ->assertOk()
            ->assertJsonPath('service', 'Maintenance')
            ->assertJsonPath('contract_version', 1)
            ->assertJsonPath('contract_sha1', ReportDataset::contractSha1())
            ->assertJsonPath('datasets.0.key', 'maintenance.ticket_issues')
            ->assertJsonPath('datasets.0.path', '/report-datasets/maintenance.ticket_issues/query')
            ->assertJsonPath('datasets.0.grain', ['store', 'date'])
            ->assertJsonPath('datasets.0.heavy', false)
            ->assertJsonPath('datasets.0.dated', true)
            ->assertJsonPath('datasets.1.key', 'maintenance.assignments')
            ->assertJsonPath('datasets.1.heavy', true)
            ->assertJsonPath('datasets.1.dated', true);

        $this->assertStringNotContainsString('max_days', (string) $response->getContent());

        $this->assertMatchesRegularExpression('/^[0-9a-f]{40}$/', $response->json('contract_sha1'));

        // The SQL behind a column is never published.
        $this->assertStringNotContainsString('ticket_issues.status', (string) $response->getContent());
        $status = collect($response->json('datasets.0.columns'))->firstWhere('key', 'status');
        $this->assertSame('enum', $status['type']);
        $this->assertContains(['value' => 'in_review', 'label' => 'In review'], $status['options']);
    }

    public function test_stream_mode_sends_ndjson_columns_rows_and_the_done_line(): void
    {
        $page = $this->postJson(self::ISSUES, $this->body(), $this->headers())->assertOk()->json();

        $response = $this->postJson(self::ISSUES, $this->body(['stream' => true, 'limit' => null]), $this->headers())->assertOk();

        $this->assertStringStartsWith('application/x-ndjson', $response->headers->get('Content-Type'));
        $lines = array_map(fn (string $line) => json_decode($line, true), explode("\n", rtrim($response->streamedContent(), "\n")));
        $rows = array_slice($lines, 1, -1);

        $this->assertSame(['columns' => $page['columns']], $lines[0]);
        $this->assertNotEmpty($rows);
        $this->assertEqualsCanonicalizing($page['rows'], $rows);
        $this->assertSame(['done' => true, 'row_count' => count($rows)], end($lines));
    }

    public function test_a_stream_query_with_a_limit_is_a_422(): void
    {
        $this->postJson(self::ISSUES, $this->body(['stream' => true, 'limit' => 10]), $this->headers())
            ->assertUnprocessable()
            ->assertJsonValidationErrors('limit');
    }
}
