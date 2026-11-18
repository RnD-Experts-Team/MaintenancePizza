<?php

namespace App\Services\PaymentHistory;

use App\Models\Category;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Models\Supplier;
use App\Models\Technician;
use App\Services\CatalogService;
use App\Services\LegacyConversion\LegacyMap;
use App\Services\PaymentService;
use App\Services\SupplierService;
use App\Support\NameKey;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

/**
 * Loads the payments history (the R&M sheet the office kept before the
 * payments screen existed) from a reviewed import plan.
 *
 * The plan is a JSON file made from the review workbook, signed off by the
 * maintenance head and the CFO. It says, for every sheet row, what to do:
 *
 *   create    a new payment (and its lines) for one sheet row
 *   amend     correct a payment already in the system, in place (PaymentService::amend)
 *   mistaken  mark a payment already in the system as a mistake
 *   keep      the sheet row is already in the system as these payments
 *   skip      leave the sheet row out (the reason is in the plan)
 *
 * Rules that hold whatever the plan says:
 *
 *   - Only payments up to `last_touchable_payment_id` (those that existed
 *     when the review was made) can be amended, marked mistaken or named as
 *     "keep". A plan naming a later one is refused before anything is written.
 *     Later payments are only read: they must come out of the run unchanged.
 *   - A payment the plan amends, marks mistaken or keeps must still be as it
 *     was in the review (same updated_at); otherwise the run stops and
 *     names it, rather than writing over a later change.
 *   - Before creating a payment, a payment entered after the review with the
 *     same amount, a week either side, holds it back (reported as "held"),
 *     unless the plan says `not_a_late_entry`.
 *   - All or nothing: one failing or held operation rolls the whole run back. A dry
 *     run does everything and then rolls back, so it reports every problem.
 *   - Every sheet row is recorded in legacy_conversion_map (`rm_sheet_row`),
 *     so a second run (or one resumed after a fix) never imports a row twice.
 */
final class PaymentHistoryImport
{
    public const MAP_ROW = 'rm_sheet_row';

    public const MAP_AMEND = 'rm_history_amend';

    /** @var array<string, int> payee key → technician id */
    private array $newTechnicians = [];

    /** @var array<string, int> payee key → supplier id */
    private array $newSuppliers = [];

    /** @var array<string, int> payment method name key → id */
    private array $methods = [];

    private int $limit = 0;

    /** The highest payment id before this run: the run's own payments come after it. */
    private int $maxBefore = 0;

    public function __construct(
        private PaymentService $payments,
        private SupplierService $suppliers,
        private CatalogService $catalog,
    ) {}

    /**
     * @param  array<string, mixed>  $plan
     * @return array<string, mixed> what happened, operation by operation, and the checks
     */
    public function run(array $plan, bool $dryRun): array
    {
        $this->validatePlan($plan);
        $this->limit = (int) $plan['last_touchable_payment_id'];

        $result = [
            'dry_run' => $dryRun,
            'counts' => ['payees_created' => 0, 'created' => 0, 'amended' => 0, 'mistaken' => 0, 'kept' => 0, 'skipped' => 0, 'already_done' => 0, 'held' => 0, 'errors' => 0],
            'created_total' => '0.00',
            'held_total' => '0.00',
            'operations' => [],
            'checks' => [],
            'committed' => false,
        ];

        $maxBefore = $this->maxBefore = (int) DB::table('payments')->max('id');
        $later = $this->fingerprintBetween($this->limit, $maxBefore);

        DB::beginTransaction();
        try {
            $this->methods = PaymentMethod::query()->pluck('id', 'name_key')->map(fn ($id) => (int) $id)->all();
            $result['counts']['payees_created'] = $this->resolvePayees((array) ($plan['payees'] ?? []), $result);

            foreach ($plan['operations'] as $i => $op) {
                $entry = ['index' => $i, 'op' => $op['op'], 'sheet_rows' => $op['sheet_rows'] ?? [], 'payment_ids' => []];
                try {
                    DB::transaction(function () use ($op, &$entry, &$result) {
                        $this->apply($op, $entry, $result);
                    });
                } catch (ValidationException $e) {
                    $entry['status'] = 'error';
                    $entry['message'] = collect($e->errors())->flatten()->implode(' ');
                    $result['counts']['errors']++;
                } catch (RuntimeException $e) {
                    $entry['status'] = 'error';
                    $entry['message'] = $e->getMessage();
                    $result['counts']['errors']++;
                }
                $result['operations'][] = $entry;
            }

            $result['checks'] = $this->checks($plan, $result, $later, $maxBefore);
            // Held rows block the commit too: the history goes in complete or not at all.
            $ok = $result['counts']['errors'] === 0 && $result['counts']['held'] === 0 && collect($result['checks'])->every(fn ($c) => $c['ok']);

            if ($dryRun || ! $ok) {
                DB::rollBack();
            } else {
                DB::commit();
                $result['committed'] = true;
            }
        } catch (Throwable $e) {
            DB::rollBack();
            throw $e;
        }

        return $result;
    }

    /**
     * Refuse a malformed plan, or one that touches a payment it may not,
     * before anything is written.
     *
     * @param  array<string, mixed>  $plan
     */
    private function validatePlan(array $plan): void
    {
        if (($plan['version'] ?? null) !== 1) {
            throw new RuntimeException('Unknown plan version (expected 1).');
        }
        if (! isset($plan['last_touchable_payment_id']) || (int) $plan['last_touchable_payment_id'] < 1) {
            throw new RuntimeException('The plan must say the last payment it may touch (last_touchable_payment_id).');
        }
        if (! is_array($plan['operations'] ?? null)) {
            throw new RuntimeException('The plan has no operations.');
        }

        $limit = (int) $plan['last_touchable_payment_id'];
        $seenRows = [];

        foreach ($plan['operations'] as $i => $op) {
            $kind = $op['op'] ?? null;
            if (! in_array($kind, ['create', 'amend', 'mistaken', 'keep', 'skip'], true)) {
                throw new RuntimeException("Operation {$i}: unknown op \"{$kind}\".");
            }

            foreach ($this->paymentIdsOf($op) as $id) {
                if ($id < 1 || $id > $limit) {
                    throw new RuntimeException("Operation {$i} names payment #{$id}, but only payments up to #{$limit} may be touched.");
                }
            }

            foreach ((array) ($op['sheet_rows'] ?? []) as $row) {
                if (isset($seenRows[(int) $row])) {
                    throw new RuntimeException("Sheet row {$row} is in operations {$seenRows[(int) $row]} and {$i}: each row goes in one operation.");
                }
                $seenRows[(int) $row] = $i;
            }

            if (in_array($kind, ['create', 'keep', 'skip'], true) && empty($op['sheet_rows'])) {
                throw new RuntimeException("Operation {$i} ({$kind}) names no sheet row.");
            }

            foreach ($this->paymentIdsOf($op) as $id) {
                if (! isset($op['reviewed_updated_at'][(string) $id])) {
                    throw new RuntimeException("Operation {$i} names payment #{$id} without saying how it looked in the review (reviewed_updated_at).");
                }
            }
        }
    }

    /**
     * @param  array<string, mixed>  $op
     * @return array<int, int>
     */
    private function paymentIdsOf(array $op): array
    {
        return match ($op['op']) {
            'amend', 'mistaken' => [(int) ($op['payment_id'] ?? 0)],
            'keep' => array_map('intval', (array) ($op['payment_ids'] ?? [])),
            default => [],
        };
    }

    /**
     * The payees the plan adds, found by name first (a second run, or one the
     * office added meanwhile), created otherwise.
     *
     * @param  array<int, array<string, mixed>>  $payees
     * @param  array<string, mixed>  $result
     */
    private function resolvePayees(array $payees, array &$result): int
    {
        $created = 0;

        foreach ($payees as $p) {
            $key = (string) $p['key'];
            $name = NameKey::tidy((string) $p['name']);
            $nameKey = NameKey::of($name);

            if ($p['kind'] === 'supplier') {
                $found = Supplier::withTrashed()->where('name_key', $nameKey)->first();
                if ($found?->trashed()) {
                    throw new RuntimeException("Supplier \"{$name}\" exists but is deleted: restore it or rename the payee in the plan.");
                }
                if ($found === null) {
                    $found = $this->suppliers->create(['name' => $name]);
                    $created++;
                }
                $this->newSuppliers[$key] = $found->id;
            } else {
                $found = Technician::withTrashed()->where('name_key', $nameKey)->first();
                if ($found?->trashed()) {
                    throw new RuntimeException("Technician \"{$name}\" exists but is deleted: restore it or rename the payee in the plan.");
                }
                if ($found === null) {
                    $categoryId = isset($p['category']) ? Category::query()->where('name_key', NameKey::of((string) $p['category']))->value('id') : null;
                    $this->catalog->createTechnician(['name' => $name, 'category_id' => $categoryId]);
                    $found = Technician::query()->where('name_key', $nameKey)->firstOrFail();
                    $created++;
                }
                $this->newTechnicians[$key] = $found->id;
            }
        }

        return $created;
    }

    /**
     * @param  array<string, mixed>  $op
     * @param  array<string, mixed>  $entry
     * @param  array<string, mixed>  $result
     */
    private function apply(array $op, array &$entry, array &$result): void
    {
        $rows = array_map('intval', (array) ($op['sheet_rows'] ?? []));

        switch ($op['op']) {
            case 'create':
                $done = $rows !== [] && collect($rows)->every(fn ($r) => LegacyMap::has(self::MAP_ROW, $r, 'payments'));
                if ($done) {
                    $entry['status'] = 'already_done';
                    $entry['payment_ids'] = LegacyMap::all(self::MAP_ROW, $rows[0], 'payments');
                    $result['counts']['already_done']++;

                    return;
                }

                $total = $this->sum(array_map(fn ($l) => $l['amount'], (array) $op['lines']));
                $late = $this->lateEntry((string) $op['paid_at'], $total);
                if ($late !== null && empty($op['not_a_late_entry'])) {
                    $entry['status'] = 'held';
                    $entry['message'] = "Payment #{$late->id} ({$late->paid_at->toDateString()}, {$late->total}) was entered after the review with the same amount. Check it is not this one, then mark the row not_a_late_entry.";
                    $result['counts']['held']++;
                    $result['held_total'] = bcadd($result['held_total'], $total, 2);

                    return;
                }

                $payment = $this->payments->create([
                    'paid_at' => $op['paid_at'],
                    ...$this->payee((array) $op['payee']),
                    'payment_method_id' => $this->method($op['payment_method'] ?? null),
                    'note' => $op['note'] ?? null,
                    'lines' => array_map(fn ($l) => [
                        'type' => $l['type'],
                        'store_id' => $l['store_id'] ?? null,
                        'other_store' => $l['other_store'] ?? null,
                        'description' => $l['description'] ?? null,
                        'amount' => $l['amount'],
                        'ticket_issue_ids' => $l['ticket_issue_ids'] ?? [],
                    ], (array) $op['lines']),
                ], []);

                if (bccomp((string) $payment->total, $total, 2) !== 0) {
                    throw new RuntimeException("Payment for sheet row(s) ".implode(', ', $rows)." came to {$payment->total}, the plan says {$total}.");
                }

                foreach ($rows as $r) {
                    LegacyMap::put(self::MAP_ROW, $r, 'payments', $payment->id);
                }
                $entry['status'] = 'created';
                $entry['payment_ids'] = [$payment->id];
                $result['counts']['created']++;
                $result['created_total'] = bcadd($result['created_total'], $total, 2);
                break;

            case 'amend':
                $id = (int) $op['payment_id'];
                $entry['payment_ids'] = [$id];
                if (LegacyMap::has(self::MAP_AMEND, $id, 'payments')) {
                    $entry['status'] = 'already_done';
                    $result['counts']['already_done']++;

                    return;
                }

                $this->assertAsReviewed($id, $op);

                $changes = (array) ($op['changes'] ?? []);
                $translated = [];
                if (array_key_exists('payment_method', $changes)) {
                    $translated['payment_method_id'] = $this->method($changes['payment_method']);
                }
                if (isset($changes['payee'])) {
                    $translated += $this->payee((array) $changes['payee']);
                }
                if (isset($changes['lines'])) {
                    $translated['lines'] = (array) $changes['lines'];
                }

                $this->payments->amend(Payment::query()->findOrFail($id), $translated, $op['note'] ?? null);
                LegacyMap::put(self::MAP_AMEND, $id, 'payments', $id);
                foreach ($rows as $r) {
                    LegacyMap::put(self::MAP_ROW, $r, 'payments', $id);
                }
                $entry['status'] = 'amended';
                $result['counts']['amended']++;
                break;

            case 'mistaken':
                $payment = Payment::query()->findOrFail((int) $op['payment_id']);
                $entry['payment_ids'] = [$payment->id];
                if ($payment->mistaken) {
                    $entry['status'] = 'already_done';
                    $result['counts']['already_done']++;

                    return;
                }
                $this->assertAsReviewed($payment->id, $op);
                $this->payments->markMistaken($payment, (string) ($op['reason'] ?? 'Marked mistaken in the payments history review.'));
                $entry['status'] = 'mistaken';
                $result['counts']['mistaken']++;
                break;

            case 'keep':
                $ids = array_map('intval', (array) $op['payment_ids']);
                foreach ($ids as $id) {
                    if (Payment::query()->findOrFail($id)->mistaken) {
                        throw new RuntimeException("Payment #{$id} was marked mistaken after the review, so the sheet row(s) ".implode(', ', $rows).' would be lost: review them again.');
                    }
                    // A payment this import amended has moved on; that change was ours.
                    if (! LegacyMap::has(self::MAP_AMEND, $id, 'payments')) {
                        $this->assertAsReviewed($id, $op);
                    }
                }
                foreach ($rows as $r) {
                    foreach ($ids as $id) {
                        LegacyMap::put(self::MAP_ROW, $r, 'payments', $id);
                    }
                }
                $entry['status'] = 'kept';
                $entry['payment_ids'] = $ids;
                $result['counts']['kept']++;
                break;

            case 'skip':
                foreach ($rows as $r) {
                    LegacyMap::put(self::MAP_ROW, $r, LegacyMap::SKIPPED, 0);
                }
                $entry['status'] = 'skipped';
                $entry['message'] = $op['reason'] ?? null;
                $result['counts']['skipped']++;
                break;
        }
    }

    /**
     * The payment must still be as it was when the review was made: someone
     * may have edited it (or marked it mistaken) since, and the plan must not
     * write over that. Compared on the raw stored updated_at.
     *
     * @param  array<string, mixed>  $op
     */
    private function assertAsReviewed(int $id, array $op): void
    {
        $expected = (string) $op['reviewed_updated_at'][(string) $id];
        $now = (string) DB::table('payments')->where('id', $id)->value('updated_at');

        if ($now !== $expected) {
            throw new RuntimeException("Payment #{$id} changed after the review (saved {$now}, the review saw {$expected}): check the change and make the plan again.");
        }
    }

    /**
     * @param  array<string, mixed>  $payee  {technician_id} | {supplier_id} | {new: key}
     * @return array{technician_id: int|null, supplier_id: int|null}
     */
    private function payee(array $payee): array
    {
        if (isset($payee['new'])) {
            $key = (string) $payee['new'];
            if (isset($this->newTechnicians[$key])) {
                return ['technician_id' => $this->newTechnicians[$key], 'supplier_id' => null];
            }
            if (isset($this->newSuppliers[$key])) {
                return ['technician_id' => null, 'supplier_id' => $this->newSuppliers[$key]];
            }
            throw new RuntimeException("Payee \"{$key}\" is not in the plan's payees.");
        }

        if (isset($payee['technician_id'])) {
            Technician::withTrashed()->findOrFail((int) $payee['technician_id']);

            return ['technician_id' => (int) $payee['technician_id'], 'supplier_id' => null];
        }
        if (isset($payee['supplier_id'])) {
            Supplier::withTrashed()->findOrFail((int) $payee['supplier_id']);

            return ['technician_id' => null, 'supplier_id' => (int) $payee['supplier_id']];
        }

        throw new RuntimeException('The payee says neither a technician, a supplier, nor a new payee.');
    }

    private function method(?string $name): ?int
    {
        if ($name === null || trim($name) === '') {
            return null;
        }

        return $this->methods[NameKey::of($name)]
            ?? throw new RuntimeException("Payment method \"{$name}\" does not exist: add it first.");
    }

    /**
     * A payment entered after the review (id above the limit, but not one
     * this run made) with the same total, paid within a week either side.
     */
    private function lateEntry(string $paidAt, string $total): ?Payment
    {
        $day = Carbon::parse($paidAt);

        return Payment::query()
            ->where('id', '>', $this->limit)
            ->where('id', '<=', $this->maxBefore)
            ->where('mistaken', false)
            ->whereBetween('paid_at', [$day->copy()->subDays(7)->toDateString(), $day->copy()->addDays(7)->toDateString()])
            ->whereBetween('total', [bcsub($total, '0.01', 2), bcadd($total, '0.01', 2)])
            ->orderBy('id')
            ->first();
    }

    /**
     * @param  array<string, mixed>  $plan
     * @param  array<string, mixed>  $result
     * @param  array<string, mixed>  $before  fingerprintBetween() before the run
     * @return array<int, array{name: string, ok: bool, detail: string}>
     */
    private function checks(array $plan, array $result, array $before, int $maxBefore): array
    {
        $checks = [];

        // The payments entered after the review (ids above the limit that
        // existed before this run) must come out exactly as they went in.
        $after = $this->fingerprintBetween($this->limit, $maxBefore);
        $checks[] = [
            'name' => "Payments after #{$this->limit} untouched",
            'ok' => $after === $before,
            'detail' => $after === $before ? "{$before['count']} payments, {$before['total']}, unchanged" : json_encode($before).' -> '.json_encode($after),
        ];

        $expected = (array) ($plan['expected'] ?? []);
        if (isset($expected['create_total'])) {
            $done = $this->createdSoFar($plan);
            $checks[] = [
                'name' => 'Money created matches the plan',
                'ok' => bccomp(bcadd($done, $result['held_total'], 2), (string) $expected['create_total'], 2) === 0,
                'detail' => "plan {$expected['create_total']}, in the system {$done}".($result['counts']['held'] > 0 ? ", held back {$result['held_total']} ({$result['counts']['held']} rows)" : ''),
            ];
        }

        $rows = collect($plan['operations'])->pluck('sheet_rows')->flatten()->filter(fn ($r) => $r !== null)->map(fn ($r) => (int) $r)->unique();
        $mapped = DB::table('legacy_conversion_map')->where('legacy_table', self::MAP_ROW)->whereIn('legacy_id', $rows->all())->distinct()->count('legacy_id');
        $checks[] = [
            'name' => 'Every sheet row accounted for',
            'ok' => $mapped + $result['counts']['held'] >= $rows->count() && (! isset($expected['sheet_rows']) || $rows->count() === (int) $expected['sheet_rows']),
            'detail' => "{$rows->count()} rows in the plan".(isset($expected['sheet_rows']) ? " (sheet has {$expected['sheet_rows']})" : '').", {$mapped} recorded, {$result['counts']['held']} held",
        ];

        return $checks;
    }

    /**
     * @return array<string, mixed>
     */
    private function fingerprintBetween(int $after, int $upTo): array
    {
        $later = DB::table('payments')->where('id', '>', $after)->where('id', '<=', max($upTo, $after));

        return [
            'count' => (clone $later)->count(),
            'total' => number_format((float) (clone $later)->sum('total'), 2, '.', ''),
            'mistaken' => (clone $later)->where('mistaken', true)->count(),
            'last_update' => (string) (clone $later)->max('updated_at'),
            'lines' => DB::table('payment_lines')->whereIn('payment_id', (clone $later)->select('id'))->count(),
            'revisions' => DB::table('payment_revisions')->whereIn('payment_id', (clone $later)->select('id'))->count(),
        ];
    }

    /**
     * The total of the payments the plan's create operations made, this run
     * or an earlier one.
     *
     * @param  array<string, mixed>  $plan
     */
    private function createdSoFar(array $plan): string
    {
        $rows = collect($plan['operations'])->where('op', 'create')->pluck('sheet_rows')->flatten()->filter(fn ($r) => $r !== null)->map(fn ($r) => (int) $r)->all();
        $ids = DB::table('legacy_conversion_map')->where('legacy_table', self::MAP_ROW)->where('new_table', 'payments')
            ->whereIn('legacy_id', $rows)->distinct()->pluck('new_id')->all();

        return number_format((float) DB::table('payments')->whereIn('id', $ids)->sum('total'), 2, '.', '');
    }

    /**
     * @param  array<int, mixed>  $amounts
     */
    private function sum(array $amounts): string
    {
        return array_reduce($amounts, fn (string $c, $a) => bcadd($c, number_format((float) $a, 2, '.', ''), 2), '0.00');
    }
}
