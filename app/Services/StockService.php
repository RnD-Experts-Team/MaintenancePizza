<?php

namespace App\Services;

use App\Enums\PartUsagePayer;
use App\Enums\StockMovementType;
use App\Exceptions\InsufficientStockException;
use App\Models\Part;
use App\Models\PartUsage;
use App\Models\StockBalance;
use App\Models\StockMovement;
use App\Models\StockMovementLine;
use App\Models\StorageLocation;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * The only writer of stock_movement_lines and stock_balances.
 *
 * The ledger is the source of truth and is append-only:
 *
 *     balance(part, location) = SUM(quantity * direction) over ALL lines
 *
 * unfiltered — `stock_movements.mistaken` is an audit flag with no arithmetic
 * effect. A mistake is corrected by writing an equal-and-opposite `reversal`
 * movement. Excluding flagged rows AND writing a reversal would correct the
 * same error twice, which is why the sum never filters.
 *
 * stock_balances is a cache of that sum, updated inside the same transaction.
 * `php artisan stock:reconcile` recomputes it from the ledger and will tell
 * you if the two ever disagree.
 */
class StockService
{
    /** Scale used for every quantity calculation. Matches decimal(_, 2). */
    private const SCALE = 2;

    private const MOVEMENT_WITH = [
        'lines.part',
        'lines.storageLocation',
        'storageLocation',
        'paidByTechnician',
        'creator',
        'notes.creator',
        'notes.attachments.creator',
        'attachments.creator',
    ];

    public function __construct(
        private NoteService $notes,
        private AttachmentService $attachments,
        private CatalogService $catalog,
        private StorageLocationService $locations,
    ) {
    }

    /**
     * Record one batch of stock moving, and apply it to the cached balances.
     *
     * @param  array<string, mixed>  $header
     * @param  array<int, array<string, mixed>>  $lines  part_id, storage_location_id, quantity, direction, unit_cost?
     * @param  bool  $allowNegative  Only ever true for reversals — see reverse().
     */
    public function record(array $header, array $lines, bool $allowNegative = false): StockMovement
    {
        return DB::transaction(function () use ($header, $lines, $allowNegative) {
            // LOAD-BEARING: lock every (part, location) this movement touches in
            // one deterministic order — part id, then location id. Two concurrent
            // movements touching the same pairs in opposite orders would
            // otherwise deadlock on InnoDB. Do not "simplify" this to a lock per
            // line as the loop below reaches it.
            $keys = collect($lines)
                ->map(fn (array $l) => [
                    'part_id' => (int) $l['part_id'],
                    'storage_location_id' => (int) $l['storage_location_id'],
                ])
                ->unique(fn (array $k) => $this->key($k['part_id'], $k['storage_location_id']))
                ->sortBy([['part_id', 'asc'], ['storage_location_id', 'asc']])
                ->values();

            // The unique index on (part_id, storage_location_id) makes this
            // race-free on the first-ever movement for a pair. firstOrCreate per
            // line would interleave inserts with locks and race instead.
            DB::table('stock_balances')->insertOrIgnore(
                $keys->map(fn (array $k) => $k + [
                    'quantity' => 0,
                    'created_at' => now(),
                    'updated_at' => now(),
                ])->all()
            );

            $balances = $this->lockBalances($keys->all());

            $movement = new StockMovement($header);
            $movement->created_by = Auth::id();
            $movement->save();

            foreach ($lines as $line) {
                $this->applyLine($movement, $line, $balances, $allowNegative);
            }

            return $movement;
        });
    }

    /**
     * Write one line and move its balance. Called only from record(), with the
     * balances already locked.
     *
     * @param  array<string, mixed>  $line
     * @param  array<string, StockBalance>  $balances
     */
    private function applyLine(StockMovement $movement, array $line, array $balances, bool $allowNegative): void
    {
        $partId = (int) $line['part_id'];
        $locationId = (int) $line['storage_location_id'];
        $direction = (int) $line['direction'] <=> 0;
        $quantity = $this->scale($line['quantity']);
        $unitCost = isset($line['unit_cost']) ? (string) $line['unit_cost'] : null;

        StockMovementLine::create([
            'stock_movement_id' => $movement->id,
            'part_id' => $partId,
            'storage_location_id' => $locationId,
            'quantity' => $quantity,
            'direction' => $direction,
            'unit_cost' => $unitCost,
            'total_cost' => $unitCost === null ? null : bcmul($quantity, $unitCost, self::SCALE),
        ]);

        $balance = $balances[$this->key($partId, $locationId)];
        // bcmath, not floats: a float round-trip on a decimal(12,2) eventually
        // produces -0.000000001 and rejects a legitimate exact-zero draw.
        $balance->quantity = bcadd(
            $this->scale($balance->quantity),
            bcmul($quantity, (string) $direction, self::SCALE),
            self::SCALE
        );

        // The authoritative check: after the lock, on the post-delta value. The
        // advisory copy in StoreStockMovementRequest is for a nicer message
        // only — it is TOCTOU-vulnerable and must never be relied on.
        if (! $allowNegative && bccomp($balance->quantity, '0', self::SCALE) < 0) {
            $available = bcsub(
                $balance->quantity,
                bcmul($quantity, (string) $direction, self::SCALE),
                self::SCALE
            );

            throw new InsufficientStockException(
                $partId,
                $locationId,
                $quantity,
                $available,
                Part::withTrashed()->find($partId)?->name,
                StorageLocation::withTrashed()->find($locationId)?->name,
            );
        }

        $balance->save();
    }

    /**
     * Lock every named balance row for update, in the caller's order.
     *
     * @param  array<int, array{part_id: int, storage_location_id: int}>  $keys
     * @return array<string, StockBalance>
     */
    private function lockBalances(array $keys): array
    {
        if ($keys === []) {
            return [];
        }

        $rows = StockBalance::query()
            ->where(function (Builder $query) use ($keys) {
                foreach ($keys as $key) {
                    $query->orWhere(fn (Builder $q) => $q
                        ->where('part_id', $key['part_id'])
                        ->where('storage_location_id', $key['storage_location_id']));
                }
            })
            ->orderBy('part_id')
            ->orderBy('storage_location_id')
            ->lockForUpdate()
            ->get();

        return $rows->keyBy(fn (StockBalance $b) => $this->key($b->part_id, $b->storage_location_id))->all();
    }

    // ------------------------------------------------------- Part usage hooks

    /**
     * A part taken off our shelf for a job: stock goes down. Rejects (and rolls
     * back the part usage with it) when there is not enough.
     */
    public function drawForPartUsage(PartUsage $usage): ?StockMovement
    {
        if (! $usage->drawsFromStorage()) {
            return null;
        }

        return $this->record([
            'moved_at' => $usage->created_at ?? now(),
            'type' => StockMovementType::Draw,
            'storage_location_id' => $usage->storage_location_id,
            'paid_by' => $usage->paid_by,
            'paid_by_technician_id' => $usage->paid_by_technician_id,
            'part_usage_id' => $usage->id,
        ], [[
            'part_id' => $usage->part_id,
            'storage_location_id' => $usage->storage_location_id,
            'quantity' => $usage->quantity,
            'direction' => -1,
            'unit_cost' => $usage->unit_cost,
        ]]);
    }

    /**
     * Parts bought for a job but not all used, handed back to a shelf: stock
     * goes up. Note the asymmetry with drawForPartUsage() — a `purchased`
     * usage with a return writes only this inbound movement, because those
     * parts are entering inventory for the first time. The ledger stays
     * literal about what physically moved.
     */
    public function returnForPartUsage(PartUsage $usage): ?StockMovement
    {
        if (! $usage->returnsToStorage()) {
            return null;
        }

        return $this->record([
            'moved_at' => $usage->created_at ?? now(),
            'type' => StockMovementType::Return,
            'storage_location_id' => $usage->returned_to_storage_location_id,
            'paid_by' => $usage->paid_by,
            'paid_by_technician_id' => $usage->paid_by_technician_id,
            'part_usage_id' => $usage->id,
        ], [[
            'part_id' => $usage->part_id,
            'storage_location_id' => $usage->returned_to_storage_location_id,
            'quantity' => $usage->returned_quantity,
            'direction' => 1,
            'unit_cost' => $usage->unit_cost,
        ]]);
    }

    /**
     * Undo every movement a part usage generated, because the usage was marked
     * mistaken. Idempotent: a movement that already has a reversal is skipped.
     *
     * @return array<int, StockMovement>
     */
    public function reverseForPartUsage(PartUsage $usage): array
    {
        return $usage->stockMovements()
            ->whereDoesntHave('reversal')
            ->where('type', '!=', StockMovementType::Reversal->value)
            ->with('lines')
            ->get()
            ->map(fn (StockMovement $m) => $this->reverse($m))
            ->all();
    }

    /**
     * Write the equal-and-opposite of a movement. The original is left exactly
     * as it was and flagged `mistaken` for display.
     */
    public function reverse(StockMovement $movement): StockMovement
    {
        return DB::transaction(function () use ($movement) {
            $movement->loadMissing('lines');

            // Already reversed: hand back the reversal that exists rather than
            // writing a second one. reverseForPartUsage() has always guarded
            // this way; the controller path did not, so pressing "mistaken"
            // twice wrote two opposite movements and took the balance past zero
            // in the other direction.
            $existing = StockMovement::query()
                ->where('reverses_stock_movement_id', $movement->id)
                ->first();
            if ($existing !== null) {
                return $existing;
            }

            $reversal = $this->record([
                'moved_at' => now(),
                'type' => StockMovementType::Reversal,
                'storage_location_id' => $movement->storage_location_id,
                'paid_by' => $movement->paid_by,
                'paid_by_technician_id' => $movement->paid_by_technician_id,
                'part_usage_id' => $movement->part_usage_id,
                'reverses_stock_movement_id' => $movement->id,
                'body' => "Reverses movement #{$movement->id}.",
            ], $movement->lines->map(fn (StockMovementLine $l) => [
                'part_id' => $l->part_id,
                'storage_location_id' => $l->storage_location_id,
                'quantity' => $l->quantity,
                'direction' => -$l->direction,
                'unit_cost' => $l->unit_cost,
            ])->all(),
                // Reversing a `return` pushes stock down, and that stock may
                // since have been consumed. Refusing here would trap the user
                // with an uncorrectable mistake, so a correction is always
                // allowed to go negative; stock:reconcile reports it. This is
                // the ONLY place allowNegative is true.
                allowNegative: true);

            $movement->update(['mistaken' => true]);

            return $reversal;
        });
    }

    // ----------------------------------------------------------------- Reads

    /**
     * @param  array<string, mixed>  $filters
     */
    public function listMovements(array $filters): LengthAwarePaginator
    {
        /** @var Builder<StockMovement> $query */
        $query = StockMovement::query()->with(self::MOVEMENT_WITH);

        if (! empty($filters['types'])) {
            $query->whereIn('type', array_filter((array) $filters['types']));
        }

        if (! empty($filters['part_ids'])) {
            $ids = array_filter((array) $filters['part_ids']);
            $query->whereHas('lines', fn (Builder $q) => $q->whereIn('part_id', $ids));
        }

        if (! empty($filters['storage_location_ids'])) {
            $ids = array_filter((array) $filters['storage_location_ids']);
            $query->whereHas('lines', fn (Builder $q) => $q->whereIn('storage_location_id', $ids));
        }

        if (! empty($filters['paid_by'])) {
            $query->whereIn('paid_by', array_filter((array) $filters['paid_by']));
        }

        if (! empty($filters['paid_by_technician_ids'])) {
            $query->whereIn('paid_by_technician_id', array_filter((array) $filters['paid_by_technician_ids']));
        }

        if (! empty($filters['moved_from'])) {
            $query->whereDate('moved_at', '>=', $filters['moved_from']);
        }

        if (! empty($filters['moved_to'])) {
            $query->whereDate('moved_at', '<=', $filters['moved_to']);
        }

        $sort = in_array($filters['sort'] ?? '', ['moved_at', 'created_at', 'id'], true)
            ? $filters['sort']
            : 'moved_at';
        $dir = strtolower((string) ($filters['dir'] ?? 'desc')) === 'asc' ? 'asc' : 'desc';
        $query->orderBy($sort, $dir);

        return $query->paginate(max(1, (int) ($filters['per_page'] ?? 15)))
            ->through(fn (StockMovement $m) => $this->presentMovement($m));
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    public function listBalances(array $filters): LengthAwarePaginator
    {
        /** @var Builder<StockBalance> $query */
        $query = StockBalance::query()->with(['part', 'storageLocation', 'storageSlot']);

        if (! empty($filters['part_ids'])) {
            $query->whereIn('part_id', array_filter((array) $filters['part_ids']));
        }

        if (! empty($filters['storage_location_ids'])) {
            $query->whereIn('storage_location_id', array_filter((array) $filters['storage_location_ids']));
        }

        // ?non_zero=1 — hide the pairs that have netted back to nothing.
        if (! empty($filters['non_zero'])) {
            $query->where('quantity', '!=', 0);
        }

        $page = $query->orderBy('part_id')->orderBy('storage_location_id')
            ->paginate(max(1, (int) ($filters['per_page'] ?? 50)));

        // ONE extra query for the whole page, not one per row.
        $valuation = $this->valuePartsFifo(
            collect($page->items())->pluck('part_id')->all()
        );

        return $page->through(fn (StockBalance $b) => $this->presentBalance(
            $b,
            $valuation[$b->part_id]['locations'][$b->storage_location_id] ?? null
        ));
    }

    /**
     * One row per PART, with the total across every location.
     *
     * The default listing is one row per (part, location) pair, which is the
     * honest shape of the data -- but it means a part on four shelves appears
     * four times and the figure a human actually wants, "how many do we have",
     * is nowhere on screen. Summing the pages client-side would be wrong: the
     * listing paginates, so a page is not the whole part.
     *
     * Part::onHand() has computed exactly this since the beginning and was
     * never serialized. This exposes it, and nothing more.
     *
     * Four queries, constant regardless of page size: the grouped page, the
     * parts on it, their per-location rows, and the paginator's own count.
     *
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<int, array<string, mixed>>
     */
    public function listBalancesByPart(array $filters): LengthAwarePaginator
    {
        $query = DB::table('stock_balances')
            ->selectRaw('part_id, SUM(quantity) as quantity, COUNT(*) as location_count, MAX(updated_at) as updated_at')
            ->groupBy('part_id');

        if (! empty($filters['part_ids'])) {
            $query->whereIn('part_id', array_filter((array) $filters['part_ids']));
        }

        if (! empty($filters['storage_location_ids'])) {
            $query->whereIn('storage_location_id', array_filter((array) $filters['storage_location_ids']));
        }

        // Applied to the TOTAL, not to each row: a part with +5 on one shelf and
        // -5 on another nets to nothing and should hide, exactly as a single
        // zeroed pair does in the ungrouped listing.
        if (! empty($filters['non_zero'])) {
            $query->havingRaw('SUM(quantity) != 0');
        }

        $page = $query->orderBy('part_id')
            ->paginate(max(1, (int) ($filters['per_page'] ?? 50)));

        $partIds = collect($page->items())->pluck('part_id')->map(fn ($id) => (int) $id)->all();

        $parts = Part::withTrashed()->whereIn('id', $partIds)->get()->keyBy('id');

        // One more query for the whole page. Value is walked from the ledger;
        // the quantity above stays from stock_balances, so it can never
        // disagree with Part::onHand() or with the ungrouped listing.
        $valuation = $this->valuePartsFifo($partIds);

        // The breakdown behind each total. Loaded for the page only, so the
        // cost does not grow with the catalogue.
        $locations = StockBalance::query()
            ->with(['storageLocation', 'storageSlot'])
            ->whereIn('part_id', $partIds)
            ->when(! empty($filters['storage_location_ids']), fn ($q) => $q->whereIn(
                'storage_location_id', array_filter((array) $filters['storage_location_ids'])
            ))
            ->when(! empty($filters['non_zero']), fn ($q) => $q->where('quantity', '!=', 0))
            ->orderBy('storage_location_id')
            ->get()
            ->groupBy('part_id');

        return $page->through(function ($row) use ($parts, $locations, $valuation) {
            $partId = (int) $row->part_id;
            $part = $parts->get($partId);
            $value = $valuation[$partId] ?? null;

            return [
                'part_id' => $partId,
                'part' => $part ? $this->catalog->presentPart($part) : null,
                // number_format, not the raw SUM: identical formatting to
                // Part::onHand(), so the two can never disagree on the wire.
                'quantity' => number_format((float) $row->quantity, 2, '.', ''),
                'value' => $value['value'] ?? null,
                'average_unit_cost' => $this->averageUnitCost($value),
                'unknown_cost_quantity' => $value['unknown_quantity'] ?? null,
                'location_count' => (int) $row->location_count,
                'locations' => ($locations->get($partId) ?? collect())
                    ->map(fn (StockBalance $b) => [
                        'storage_location_id' => $b->storage_location_id,
                        'storage_location' => $b->relationLoaded('storageLocation') && $b->storageLocation
                            ? $this->locations->present($b->storageLocation)
                            : null,
                        'storage_slot' => $b->relationLoaded('storageSlot') && $b->storageSlot
                            ? $this->locations->presentSlot($b->storageSlot)
                            : null,
                        'quantity' => $b->quantity,
                    ])->values()->all(),
                'updated_at' => $row->updated_at,
            ];
        });
    }

    /**
     * Value divided by quantity. NULL at zero quantity, not zero -- dividing by
     * nothing has no answer, and "0.0000 each" would read as free.
     *
     * @param  array<string, mixed>|null  $valuation
     */
    private function averageUnitCost(?array $valuation): ?string
    {
        if ($valuation === null) {
            return null;
        }

        $quantity = $this->scale($valuation['quantity'] ?? 0);
        if (bccomp($quantity, '0', self::SCALE) === 0) {
            return null;
        }

        return number_format(
            (float) bcdiv((string) $valuation['value'], $quantity, 6),
            4, '.', ''
        );
    }

    /* ------------------------------------------------------------ Valuation */

    /**
     * What the stock is worth, FIFO, computed from the ledger.
     *
     * Nothing is stored. FIFO is a pure function of the ordered movement lines
     * -- inbound opens a lot, outbound eats the oldest -- so the lots are walked
     * on read rather than kept in a second set of tables that would then need
     * their own reversal handling, drift detection and repair command to stay
     * honest. The ledger is already the source of truth; this reads it.
     *
     * THE ONE THING TO KNOW: a BACKDATED receipt changes what earlier issues are
     * deemed to have cost, because the walk is redone from scratch each time.
     * Textbook FIFO restates for exactly this reason. The rule to tell people is
     * "the figures always reflect the ledger as it stands now".
     *
     * Walked per PART, not per pair, because a transfer crosses two locations in
     * one movement and the destination has to inherit what the source gave up --
     * which a per-pair walk cannot see.
     *
     * One query per call, then an in-memory walk.
     *
     * @param  list<int>  $partIds
     * @return array<int, array{
     *     locations: array<int, array{quantity: string, value: string, unknown_quantity: string}>,
     *     quantity: string, value: string, unknown_quantity: string
     * }>  keyed by part id
     */
    public function valuePartsFifo(array $partIds): array
    {
        $partIds = array_values(array_unique(array_map('intval', $partIds)));
        if ($partIds === []) {
            return [];
        }

        $lines = DB::table('stock_movement_lines as l')
            ->join('stock_movements as m', 'm.id', '=', 'l.stock_movement_id')
            ->whereIn('l.part_id', $partIds)
            ->orderBy('m.moved_at')
            ->orderBy('m.id')
            // Outbound before inbound WITHIN one movement, so a transfer's
            // destination line can inherit the cost its source line released.
            ->orderBy('l.direction')
            ->orderBy('l.id')
            ->get([
                'l.id', 'l.part_id', 'l.storage_location_id', 'l.quantity',
                'l.direction', 'l.unit_cost',
                'm.id as movement_id', 'm.reverses_stock_movement_id as reverses_movement_id',
            ]);

        // Which lines belong to which movement, so a reversal can find what it
        // undoes. reverses_stock_movement_id already exists on the movement --
        // no column had to be added to record this.
        $byMovement = [];
        foreach ($lines as $line) {
            $byMovement[(int) $line->movement_id][] = $line;
        }

        $out = [];
        foreach ($lines->groupBy('part_id') as $partId => $partLines) {
            $out[(int) $partId] = $this->walkFifo($partLines, $byMovement);
        }

        return $out;
    }

    /**
     * One part's whole history, oldest movement first.
     *
     * @param  \Illuminate\Support\Collection<int, object>  $lines
     * @param  array<int, list<object>>  $byMovement
     * @return array<string, mixed>
     */
    private function walkFifo($lines, array $byMovement): array
    {
        /** @var array<int, list<array{qty: string, cost: string, known: bool, line: int}>> $lots */
        $lots = [];
        /** @var list<array{qty: string, cost: string, known: bool}> $released */
        $released = [];
        /** @var array<int, list<array{loc: int, idx: int, qty: string}>> $consumedBy */
        $consumedBy = [];
        $lastKnownCost = null;
        $currentMovement = null;

        foreach ($lines as $line) {
            $locationId = (int) $line->storage_location_id;
            $lots[$locationId] ??= [];

            // The transfer pool only carries WITHIN one movement. Anything left
            // at the boundary was an ordinary issue, and a later receipt must
            // NOT inherit its cost -- that would price new stock at what old
            // stock left at.
            if ($currentMovement !== $line->movement_id) {
                $released = [];
                $currentMovement = $line->movement_id;
            }

            // A reversal undoes ITS OWN target, not whatever is oldest. Without
            // this, reversing the dearer of two purchases would eat the cheaper
            // lot and misprice everything after it.
            $target = $line->reverses_movement_id === null
                ? null
                : $this->reversalTarget($line, $byMovement);

            if ($target !== null) {
                if ((int) $target->direction > 0) {
                    $this->unopenLots($lots[$locationId], $consumedBy, $line, $target);
                } else {
                    $this->restoreLots($lots[$locationId], $consumedBy, $target);
                }
                continue;
            }

            if ((int) $line->direction < 0) {
                $released = array_merge(
                    $released,
                    $this->consumeLots($lots[$locationId], $this->scale($line->quantity), $consumedBy, (int) $line->id, $locationId)
                );
                continue;
            }

            $remaining = $this->scale($line->quantity);

            // 1. Anything a sibling outbound line just released, at its own cost
            //    and in the order it came off. Moving stock between shelves must
            //    not change what it is worth.
            while ($released !== [] && bccomp($remaining, '0', self::SCALE) > 0) {
                $entry = array_shift($released);
                $take = bccomp($entry['qty'], $remaining, self::SCALE) < 0 ? $entry['qty'] : $remaining;

                $lots[$locationId][] = [
                    'qty' => $take, 'cost' => $entry['cost'], 'known' => $entry['known'], 'line' => (int) $line->id,
                ];
                $remaining = bcsub($remaining, $take, self::SCALE);

                $left = bcsub($entry['qty'], $take, self::SCALE);
                if (bccomp($left, '0', self::SCALE) > 0) {
                    array_unshift($released, ['qty' => $left, 'cost' => $entry['cost'], 'known' => $entry['known']]);
                }
            }

            if (bccomp($remaining, '0', self::SCALE) <= 0) {
                continue;
            }

            // 2. the line's own price, 3. the last one we saw, 4. nothing.
            //    We never invent a price -- unpriced stock is reported separately
            //    rather than guessed at, so an incomplete value is visibly
            //    incomplete instead of quietly wrong.
            if ($line->unit_cost !== null) {
                $cost = (string) $line->unit_cost;
                $known = true;
                $lastKnownCost = $cost;
            } elseif ($lastKnownCost !== null) {
                $cost = $lastKnownCost;
                $known = true;
            } else {
                $cost = '0.0000';
                $known = false;
            }

            $lots[$locationId][] = [
                'qty' => $remaining, 'cost' => $cost, 'known' => $known, 'line' => (int) $line->id,
            ];
        }

        return $this->totalLots($lots);
    }

    /**
     * The line a reversal line undoes.
     *
     * reverse() builds its lines by mapping over the original's in order, so the
     * correspondence is positional -- but only among lines that match on part,
     * location and opposite direction, since a movement may carry several parts.
     * No match returns null and the line falls back to ordinary oldest-first
     * costing, which is the behaviour before any of this existed.
     *
     * @param  array<int, list<object>>  $byMovement
     */
    private function reversalTarget(object $line, array $byMovement): ?object
    {
        $candidates = array_values(array_filter(
            $byMovement[(int) $line->reverses_movement_id] ?? [],
            fn ($t) => (int) $t->part_id === (int) $line->part_id
                && (int) $t->storage_location_id === (int) $line->storage_location_id
                && (int) $t->direction === -((int) $line->direction)
        ));

        if ($candidates === []) {
            return null;
        }

        // Which of this movement's own lines this is, among the ones that could
        // pair -- so two lines of the same part at the same shelf still line up.
        $siblings = array_values(array_filter(
            $byMovement[(int) $line->movement_id] ?? [],
            fn ($t) => (int) $t->part_id === (int) $line->part_id
                && (int) $t->storage_location_id === (int) $line->storage_location_id
                && (int) $t->direction === (int) $line->direction
        ));
        $position = 0;
        foreach ($siblings as $i => $sibling) {
            if ((int) $sibling->id === (int) $line->id) {
                $position = $i;
                break;
            }
        }

        return $candidates[$position] ?? $candidates[0];
    }

    /**
     * Reversing a RECEIPT: take back what that line brought in, from the lots it
     * opened -- not from whatever is oldest.
     *
     * Whatever has already gone out the door cannot be taken back, so the
     * remainder becomes a negative lot at that lot's own price. Never blocked: a
     * correction must never be trapped, and the balance is allowed negative for
     * exactly the same reason.
     *
     * @param  list<array{qty: string, cost: string, known: bool, line: int}>  $lots
     * @param  array<int, list<array{loc: int, idx: int, qty: string}>>  $consumedBy
     */
    private function unopenLots(array &$lots, array &$consumedBy, object $line, object $target): void
    {
        $remaining = $this->scale($line->quantity);
        $cost = '0.0000';
        $known = false;

        foreach ($lots as $i => $lot) {
            if ((int) $lot['line'] !== (int) $target->id) {
                continue;
            }
            $cost = $lot['cost'];
            $known = $lot['known'];

            if (bccomp($remaining, '0', self::SCALE) <= 0) {
                break;
            }
            if (bccomp($lot['qty'], '0', self::SCALE) <= 0) {
                continue;
            }

            $take = bccomp($lot['qty'], $remaining, self::SCALE) < 0 ? $lot['qty'] : $remaining;
            $lots[$i]['qty'] = bcsub($lot['qty'], $take, self::SCALE);
            $remaining = bcsub($remaining, $take, self::SCALE);
        }

        if (bccomp($remaining, '0', self::SCALE) > 0) {
            $lots[] = [
                'qty' => bcsub('0', $remaining, self::SCALE),
                'cost' => $cost,
                'known' => $known,
                'line' => (int) $line->id,
            ];
        }
    }

    /**
     * Reversing an ISSUE: give the quantity back to the lots it came from, at
     * the prices it took them at. No new lot is opened -- the goods were never
     * really issued, so they are still the same lot, and because FIFO orders by
     * arrival they go back to the front of the queue.
     *
     * @param  list<array{qty: string, cost: string, known: bool, line: int}>  $lots
     * @param  array<int, list<array{loc: int, idx: int, qty: string}>>  $consumedBy
     */
    private function restoreLots(array &$lots, array &$consumedBy, object $target): void
    {
        foreach ($consumedBy[(int) $target->id] ?? [] as $entry) {
            if (! isset($lots[$entry['idx']])) {
                continue;
            }
            $lots[$entry['idx']]['qty'] = bcadd($lots[$entry['idx']]['qty'], $entry['qty'], self::SCALE);
        }

        unset($consumedBy[(int) $target->id]);
    }

    /**
     * Take a quantity off the oldest lots, recording what came off which one so
     * a reversal can put it back, and reporting it so a sibling transfer line
     * can inherit it.
     *
     * A shortfall -- issuing more than is there, which only a reversal can
     * produce -- leaves a NEGATIVE lot rather than throwing. Value then tracks
     * quantity below zero, exactly as the cached balance already does.
     *
     * @param  list<array{qty: string, cost: string, known: bool, line: int}>  $lots
     * @param  array<int, list<array{loc: int, idx: int, qty: string}>>  $consumedBy
     * @return list<array{qty: string, cost: string, known: bool}>
     */
    private function consumeLots(array &$lots, string $quantity, array &$consumedBy, int $lineId, int $locationId): array
    {
        $taken = [];
        $remaining = $quantity;

        foreach ($lots as $i => $lot) {
            if (bccomp($remaining, '0', self::SCALE) <= 0) {
                break;
            }
            if (bccomp($lot['qty'], '0', self::SCALE) <= 0) {
                continue;
            }

            $take = bccomp($lot['qty'], $remaining, self::SCALE) < 0 ? $lot['qty'] : $remaining;
            $lots[$i]['qty'] = bcsub($lot['qty'], $take, self::SCALE);
            $remaining = bcsub($remaining, $take, self::SCALE);

            $consumedBy[$lineId][] = ['loc' => $locationId, 'idx' => $i, 'qty' => $take];
            $taken[] = ['qty' => $take, 'cost' => $lot['cost'], 'known' => $lot['known']];
        }

        if (bccomp($remaining, '0', self::SCALE) > 0) {
            $cost = $taken !== [] ? $taken[count($taken) - 1]['cost'] : '0.0000';
            $known = $taken !== [] ? $taken[count($taken) - 1]['known'] : false;

            $lots[] = [
                'qty' => bcsub('0', $remaining, self::SCALE),
                'cost' => $cost,
                'known' => $known,
                'line' => $lineId,
            ];
            $taken[] = ['qty' => $remaining, 'cost' => $cost, 'known' => $known];
        }

        return $taken;
    }

    /**
     * @param  array<int, list<array{qty: string, cost: string, known: bool, line: int}>>  $lots
     * @return array<string, mixed>
     */
    private function totalLots(array $lots): array
    {
        $locations = [];
        $totalQty = '0.00';
        $totalValue = '0.00';
        $totalUnknown = '0.00';

        foreach ($lots as $locationId => $locationLots) {
            $qty = '0.00';
            $value = '0.00';
            $unknown = '0.00';

            foreach ($locationLots as $lot) {
                $qty = bcadd($qty, $lot['qty'], self::SCALE);
                $value = bcadd($value, bcmul($lot['qty'], $lot['cost'], self::SCALE), self::SCALE);
                if (! $lot['known']) {
                    $unknown = bcadd($unknown, $lot['qty'], self::SCALE);
                }
            }

            $locations[$locationId] = [
                'quantity' => $qty,
                'value' => $value,
                'unknown_quantity' => $unknown,
            ];

            $totalQty = bcadd($totalQty, $qty, self::SCALE);
            $totalValue = bcadd($totalValue, $value, self::SCALE);
            $totalUnknown = bcadd($totalUnknown, $unknown, self::SCALE);
        }

        return [
            'locations' => $locations,
            'quantity' => $totalQty,
            'value' => $totalValue,
            'unknown_quantity' => $totalUnknown,
        ];
    }

    /**
     * What we have paid for this part, and when.
     *
     * The honest answer to "what does this cost us" once the price has moved:
     * not one number, but the actual purchases.
     *
     * @return list<array<string, mixed>>
     */
    public function priceHistory(int $partId): array
    {
        $lines = DB::table('stock_movement_lines as l')
            ->join('stock_movements as m', 'm.id', '=', 'l.stock_movement_id')
            ->where('l.part_id', $partId)
            ->where('l.direction', 1)
            ->whereNotNull('l.unit_cost')
            ->orderByDesc('m.moved_at')
            ->orderByDesc('l.id')
            ->limit(50)
            ->get(['l.id', 'l.storage_location_id', 'l.quantity', 'l.unit_cost', 'm.moved_at', 'm.id as movement_id']);

        $locations = StorageLocation::withTrashed()
            ->whereIn('id', $lines->pluck('storage_location_id')->unique())
            ->get()->keyBy('id');

        return $lines->map(fn ($line) => [
            'storage_location_id' => (int) $line->storage_location_id,
            'storage_location' => ($l = $locations->get($line->storage_location_id))
                ? ['id' => $l->id, 'name' => $l->name]
                : null,
            'received_at' => $line->moved_at,
            'quantity' => $this->scale($line->quantity),
            'unit_cost' => $line->unit_cost,
            'total_cost' => bcmul($this->scale($line->quantity), (string) $line->unit_cost, self::SCALE),
            'stock_movement_id' => (int) $line->movement_id,
        ])->all();
    }


    /**
     * What the ledger says is on hand, ignoring the cache entirely. Used by
     * stock:reconcile and by the tests that prove the cache is honest.
     *
     * @return array<string, string>  "partId:locationId" => quantity
     */
    public function ledgerBalances(): array
    {
        return DB::table('stock_movement_lines')
            ->selectRaw('part_id, storage_location_id, SUM(quantity * direction) as quantity')
            ->groupBy('part_id', 'storage_location_id')
            ->get()
            ->mapWithKeys(fn ($row) => [
                $this->key((int) $row->part_id, (int) $row->storage_location_id) => $this->scale($row->quantity),
            ])
            ->all();
    }

    // ------------------------------------------------------------ Presenters

    /**
     * @return array<string, mixed>
     */
    public function presentMovement(StockMovement $movement): array
    {
        return [
            'id' => $movement->id,
            'moved_at' => $movement->moved_at,
            'type' => [
                'value' => $movement->type->value,
                'label' => $movement->type->label(),
            ],
            'storage_location_id' => $movement->storage_location_id,
            'storage_location' => $movement->relationLoaded('storageLocation') && $movement->storageLocation
                ? $this->locations->present($movement->storageLocation)
                : null,
            'paid_by' => $movement->paid_by
                ? ['value' => $movement->paid_by->value, 'label' => $movement->paid_by->label()]
                : null,
            'paid_by_technician_id' => $movement->paid_by_technician_id,
            'paid_by_technician' => $movement->relationLoaded('paidByTechnician') && $movement->paidByTechnician
                ? $this->catalog->presentTechnician($movement->paidByTechnician)
                : null,
            'body' => $movement->body,
            'part_usage_id' => $movement->part_usage_id,
            'reverses_stock_movement_id' => $movement->reverses_stock_movement_id,
            'mistaken' => $movement->mistaken,
            'lines' => $movement->relationLoaded('lines')
                ? $movement->lines->map(fn (StockMovementLine $l) => $this->presentMovementLine($l))->all()
                : null,
            'total_cost' => $movement->relationLoaded('lines')
                ? $movement->lines->reduce(
                    fn (string $carry, StockMovementLine $l) => bcadd($carry, $this->scale($l->total_cost ?? 0), self::SCALE),
                    '0.00'
                )
                : null,
            'notes' => $this->notes->presentMany($movement),
            'attachments' => $this->attachments->presentMany($movement),
            'created_by' => $movement->created_by,
            'creator' => $movement->relationLoaded('creator') && $movement->creator
                ? $this->catalog->presentUser($movement->creator)
                : null,
            'created_at' => $movement->created_at,
            'updated_at' => $movement->updated_at,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function presentMovementLine(StockMovementLine $line): array
    {
        return [
            'id' => $line->id,
            'part_id' => $line->part_id,
            'part' => $line->relationLoaded('part') && $line->part
                ? $this->catalog->presentPart($line->part)
                : null,
            'storage_location_id' => $line->storage_location_id,
            'storage_location' => $line->relationLoaded('storageLocation') && $line->storageLocation
                ? $this->locations->present($line->storageLocation)
                : null,
            'quantity' => $line->quantity,
            'direction' => $line->direction,
            // The signed amount this line contributes to the balance.
            'signed_quantity' => bcmul($this->scale($line->quantity), (string) $line->direction, self::SCALE),
            'unit_cost' => $line->unit_cost,
            'total_cost' => $line->total_cost,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function presentBalance(StockBalance $balance, ?array $valuation = null): array
    {
        return [
            'part_id' => $balance->part_id,
            'part' => $balance->relationLoaded('part') && $balance->part
                ? $this->catalog->presentPart($balance->part)
                : null,
            'storage_location_id' => $balance->storage_location_id,
            'storage_location' => $balance->relationLoaded('storageLocation') && $balance->storageLocation
                ? $this->locations->present($balance->storageLocation)
                : null,
            // Where inside that location it sits. Null means nobody has said --
            // which is different from "nowhere", so do not substitute a dash here.
            'storage_slot' => $balance->relationLoaded('storageSlot') && $balance->storageSlot
                ? $this->locations->presentSlot($balance->storageSlot)
                : null,
            // Quantity from the cache, value from the layers. That split is
            // deliberate: it is what makes this figure incapable of disagreeing
            // with Part::onHand() or with the ungrouped listing.
            'quantity' => $balance->quantity,
            'value' => $valuation['value'] ?? null,
            'average_unit_cost' => $this->averageUnitCost($valuation),
            // How much of that quantity we have no price for. Reported next to
            // every value so the blind spot is visible rather than folded in.
            'unknown_cost_quantity' => $valuation['unknown_quantity'] ?? null,
            'updated_at' => $balance->updated_at,
        ];
    }

    // ---------------------------------------------------------------- Helpers

    private function key(int $partId, int $locationId): string
    {
        return $partId . ':' . $locationId;
    }

    /**
     * Normalise anything numeric to a fixed-scale decimal string for bcmath.
     */
    private function scale(mixed $value): string
    {
        return number_format((float) $value, self::SCALE, '.', '');
    }
}
