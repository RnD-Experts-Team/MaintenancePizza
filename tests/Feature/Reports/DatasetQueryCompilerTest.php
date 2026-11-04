<?php

namespace Tests\Feature\Reports;

use App\Reports\Contract\Column;
use App\Reports\Contract\DatasetQueryCompiler;
use App\Reports\Contract\ReportDataset;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * The compiler against a table on the in-memory sqlite connection, through a
 * dataset that offers some of its columns and hides `secret`.
 */
class DatasetQueryCompilerTest extends TestCase
{
    private ReportDataset $dataset;

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('sales_test', function (Blueprint $table) {
            $table->id();
            $table->string('store');
            $table->date('day');
            $table->integer('orders');
            $table->string('note')->nullable();
            $table->integer('secret')->default(0);
        });

        $this->dataset = new SalesTestDataset;
    }

    private function seedStores(): void
    {
        $rows = [];
        foreach (['03795-00001', '03795-00002', '03795-00003', '03795-00004', '03795-00005'] as $s => $store) {
            foreach (['2026-10-21', '2026-10-22'] as $d => $day) {
                $rows[] = ['store' => $store, 'day' => $day, 'orders' => 10 * ($s + 1) + $d, 'note' => null];
                $rows[] = ['store' => $store, 'day' => $day, 'orders' => 1, 'note' => null];
            }
        }
        DB::table('sales_test')->insert($rows);
    }

    private function ask(array $query, ?ReportDataset $dataset = null): array
    {
        return (new DatasetQueryCompiler)->run($dataset ?? $this->dataset, $query + [
            'stores' => [],
            'date_from' => '2026-10-01',
            'date_to' => '2026-10-31',
        ]);
    }

    private function errors(array $query, ?ReportDataset $dataset = null): array
    {
        try {
            $this->ask($query, $dataset);
        } catch (ValidationException $e) {
            return $e->errors();
        }

        $this->fail('The query was accepted.');
    }

    public function test_it_groups_aggregates_and_returns_positional_rows(): void
    {
        $this->seedStores();

        $result = $this->ask([
            'stores' => ['03795-00002', '03795-00004'],
            'columns' => ['store', 'date'],
            'group_by' => ['store', 'date'],
            'aggregates' => [['column' => 'orders', 'fn' => 'sum', 'alias' => 'orders']],
            'sort' => [['column' => 'orders', 'dir' => 'desc']],
        ]);

        $this->assertSame([['key' => 'store', 'type' => 'string'], ['key' => 'date', 'type' => 'date'], ['key' => 'orders', 'type' => 'integer']], $result['columns']);
        $this->assertSame([
            ['03795-00004', '2026-10-22', 42],
            ['03795-00004', '2026-10-21', 41],
            ['03795-00002', '2026-10-22', 22],
            ['03795-00002', '2026-10-21', 21],
        ], $result['rows']);
        $this->assertNull($result['next_cursor']);
        $this->assertSame(1, $result['meta']['contract_version']);
    }

    public function test_only_offered_columns_ops_and_functions_reach_the_sql(): void
    {
        $this->assertSame(['Unknown column secret.'], $this->errors(['columns' => ['store', 'secret']])['columns']);
        $this->assertSame(['orders cannot be grouped; ask for it through an aggregate.'], $this->errors(['columns' => ['orders']])['columns']);
        $this->assertSame(['avg is not offered for orders.'], $this->errors([
            'aggregates' => [['column' => 'orders', 'fn' => 'avg']],
        ])['aggregates.0.fn']);
        $this->assertSame(['Unknown column secret.'], $this->errors([
            'columns' => ['store'],
            'filters' => [['column' => 'secret', 'op' => 'eq', 'value' => 1]],
        ])['filters.0.column']);
        $this->assertArrayHasKey('sort.0.column', $this->errors([
            'columns' => ['store'],
            'sort' => [['column' => 'date', 'dir' => 'asc']],
        ]));
        $this->assertSame(['contains works on text columns only.'], $this->errors([
            'columns' => ['store'],
            'filters' => [['column' => 'orders', 'op' => 'contains', 'value' => '1']],
        ])['filters.0.value']);
        $this->assertSame(['Send a list of one or more values.'], $this->errors([
            'columns' => ['store'],
            'filters' => [['column' => 'store', 'op' => 'in', 'value' => []]],
        ])['filters.0.value']);
    }

    public function test_any_period_and_any_long_list_is_answered(): void
    {
        $this->seedStores();

        $result = $this->ask([
            'columns' => ['store'],
            'filters' => [['column' => 'store', 'op' => 'in', 'value' => array_map(fn (int $i) => sprintf('03795-%05d', $i), range(1, 600))]],
            'date_from' => '2000-01-01',
            'date_to' => '2026-10-31',
        ]);

        $this->assertCount(5, $result['rows']);
    }

    public function test_the_cursor_pages_through_every_group_exactly_once(): void
    {
        $this->seedStores();
        $query = [
            'columns' => ['store', 'date'],
            'aggregates' => [['column' => 'orders', 'fn' => 'sum', 'alias' => 'orders']],
            'sort' => [['column' => 'orders', 'dir' => 'desc']],
        ];

        $all = $this->ask($query)['rows'];
        $paged = [];
        $cursor = null;
        $pages = 0;
        do {
            $page = $this->ask($query + ['limit' => 3, 'cursor' => $cursor]);
            $paged = [...$paged, ...$page['rows']];
            $cursor = $page['next_cursor'];
            $this->assertSame($cursor !== null, $page['truncated']);
            $pages++;
        } while ($cursor !== null);

        $this->assertCount(10, $all);
        $this->assertSame($all, $paged);
        $this->assertSame(4, $pages);
    }

    public function test_a_tampered_or_borrowed_cursor_is_refused(): void
    {
        $this->seedStores();
        $query = ['columns' => ['store', 'date'], 'limit' => 2];
        $cursor = $this->ask($query)['next_cursor'];

        $this->assertSame(['The cursor is invalid or belongs to another query.'], $this->errors($query + ['cursor' => 'x'.$cursor])['cursor']);
        $this->assertSame(['The cursor is invalid or belongs to another query.'], $this->errors([
            'columns' => ['store', 'date'],
            'stores' => ['03795-00001'],
            'cursor' => $cursor,
        ])['cursor']);
    }

    public function test_a_cursor_of_another_dataset_is_refused(): void
    {
        $this->seedStores();
        $query = ['columns' => ['store', 'date'], 'limit' => 2];
        $cursor = $this->ask($query)['next_cursor'];

        // The same query and the same table, only the dataset key differs.
        $this->assertSame(['The cursor is invalid or belongs to another query.'], $this->errors(
            $query + ['cursor' => $cursor],
            new OtherSalesTestDataset,
        )['cursor']);
        $this->assertCount(2, $this->ask($query + ['cursor' => $cursor])['rows']);
    }

    public function test_a_cursor_with_a_bad_signature_is_refused_even_when_its_payload_is_intact(): void
    {
        $this->seedStores();
        $query = ['columns' => ['store', 'date'], 'limit' => 2];
        [$payload] = explode('.', $this->ask($query)['next_cursor']);

        $this->assertSame(['The cursor is invalid or belongs to another query.'], $this->errors(
            $query + ['cursor' => $payload.'.'.str_repeat('0', 64)],
        )['cursor']);
        $this->assertSame(['The cursor is invalid or belongs to another query.'], $this->errors(
            $query + ['cursor' => $payload],
        )['cursor']);
    }

    public function test_a_cursor_signed_with_the_bare_app_key_is_refused(): void
    {
        $this->seedStores();
        $query = ['columns' => ['store', 'date'], 'limit' => 2];
        [$payload] = explode('.', $this->ask($query)['next_cursor']);

        // The cursor key is app.key behind a prefix, so a signature made elsewhere with app.key is no good.
        $this->assertSame(['The cursor is invalid or belongs to another query.'], $this->errors(
            $query + ['cursor' => $payload.'.'.hash_hmac('sha256', $payload, (string) config('app.key'))],
        )['cursor']);
        $this->assertSame(
            $payload.'.'.hash_hmac('sha256', $payload, 'report-cursor|'.config('app.key')),
            $this->ask($query)['next_cursor'],
        );
    }

    public function test_a_filter_on_a_column_name_filters_rows_even_when_an_aggregate_has_that_alias(): void
    {
        $this->seedStores();

        // Store 2 has rows of 20 and 1 on the 21st, 21 and 1 on the 22nd. As a row filter only
        // the 21 survives; as HAVING on the sums (21 and 22) both days would.
        $result = $this->ask([
            'stores' => ['03795-00002'],
            'group_by' => ['date'],
            'aggregates' => [['column' => 'orders', 'fn' => 'sum', 'alias' => 'orders']],
            'filters' => [['column' => 'orders', 'op' => 'gt', 'value' => 20]],
        ]);

        $this->assertSame([['2026-10-22', 21]], $result['rows']);
    }

    public function test_a_filter_on_an_aggregate_alias_filters_the_groups(): void
    {
        $this->seedStores();

        $result = $this->ask([
            'stores' => ['03795-00002'],
            'group_by' => ['date'],
            'aggregates' => [['column' => 'orders', 'fn' => 'sum', 'alias' => 'total']],
            'filters' => [['column' => 'total', 'op' => 'gt', 'value' => 21]],
        ]);

        $this->assertSame([['2026-10-22', 22]], $result['rows']);
    }

    public function test_the_limit_is_capped_at_5000(): void
    {
        foreach (array_chunk(range(1, 5003), 500) as $chunk) {
            DB::table('sales_test')->insert(array_map(fn () => ['store' => '03795-00001', 'day' => '2026-10-21', 'orders' => 1], $chunk));
        }

        $result = $this->ask(['columns' => ['ref'], 'limit' => 99999]);

        $this->assertCount(5000, $result['rows']);
        $this->assertTrue($result['truncated']);
        $this->assertNotNull($result['next_cursor']);
    }

    public function test_contains_matches_wildcards_literally(): void
    {
        DB::table('sales_test')->insert([
            ['store' => '03795-00001', 'day' => '2026-10-21', 'orders' => 1, 'note' => '50% off'],
            ['store' => '03795-00002', 'day' => '2026-10-21', 'orders' => 1, 'note' => '50 off'],
            ['store' => '03795-00003', 'day' => '2026-10-21', 'orders' => 1, 'note' => 'a_b'],
        ]);

        $notes = fn (string $text) => array_column($this->ask([
            'columns' => ['note'],
            'filters' => [['column' => 'note', 'op' => 'contains', 'value' => $text]],
        ])['rows'], 0);

        $this->assertSame(['50% off'], $notes('%'));
        $this->assertSame(['a_b'], $notes('_'));
        $this->assertSame(['50 off', '50% off'], $notes('50'));
    }
}

/** A table offering some of its columns and hiding secret. */
class SalesTestDataset extends ReportDataset
{
    public const KEY = 'test.sales';

    public function label(): string
    {
        return 'Sales';
    }

    public function grain(): array
    {
        return ['store', 'date'];
    }

    public function columns(): array
    {
        return [
            new Column('ref', 'Row', 'integer', 'id', groupable: true),
            new Column('store', 'Store', 'string', 'store', groupable: true),
            new Column('date', 'Date', 'date', 'day', groupable: true),
            new Column('orders', 'Orders', 'integer', 'orders', measure: true, aggregations: ['sum', 'max']),
            new Column('note', 'Note', 'string', 'note', groupable: true),
        ];
    }

    public function baseQuery(): Builder
    {
        return DB::table('sales_test');
    }

    public function storeColumn(): string
    {
        return 'store';
    }

    public function dateColumn(): string
    {
        return 'day';
    }

    public function storeKeys(array $codes): array
    {
        return $codes;
    }
}

/** Same table under another key: a cursor of one is no good for the other. */
class OtherSalesTestDataset extends SalesTestDataset
{
    public const KEY = 'test.other';
}
