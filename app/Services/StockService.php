<?php

namespace App\Services;

use App\Enums\StockMovementType;
use App\Exceptions\InsufficientStockException;
use App\Models\Part;
use App\Models\PartUnit;
use App\Models\StockBalance;
use App\Models\StockBalancePlace;
use App\Models\StockMovement;
use App\Models\StockMovementLine;
use App\Models\StorageLocation;
use App\Models\StoragePlaceValue;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The only writer of stock_movement_lines and stock_balances.
 *
 * The ledger is the source of truth and is append-only:
 *
 *     balance(part, location) = SUM(quantity * direction) over ALL lines
 *
 * unfiltered — `stock_movements.mistaken` is an audit flag with no arithmetic
 * effect. A mistake is corrected by writing an equal-and-opposite `undone`
 * movement. Excluding flagged rows AND writing an undo would correct the
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

    public const MOVEMENT_WITH = [
        'lines.part',
        'lines.storageLocation',
        'lines.units',
        'ticketIssue.ticket.store',
        'ticketIssue.issue',
        'paymentLine.payment.technician',
        'paymentLine.payment.supplier',
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
     * @param  array<int, array<string, mixed>>  $lines  part_id, storage_location_id, quantity, direction, unit_cost?,
     *                                                serials? (serial numbers of a serial-tracked part, one per piece),
     *                                                serials_key? (the 422 key for them; default lines.{i}.serials)
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

            $written = [];
            foreach ($lines as $i => $line) {
                $written[$i] = $this->applyLine($movement, $line, $balances, $allowNegative);
            }

            $this->moveUnits($movement, $lines, $written);

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
    private function applyLine(StockMovement $movement, array $line, array $balances, bool $allowNegative): StockMovementLine
    {
        $partId = (int) $line['part_id'];
        $locationId = (int) $line['storage_location_id'];
        $direction = (int) $line['direction'] <=> 0;
        $quantity = $this->scale($line['quantity']);
        $unitCost = isset($line['unit_cost']) ? (string) $line['unit_cost'] : null;

        $written = StockMovementLine::create([
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

        return $written;
    }

    /**
     * Move the serial-tracked pieces a movement's lines name
     * (docs/MAINTENANCE-V2.md §5), after its quantities are written:
     *
     *   out lines   the units must be in_stock at that line's location;
     *               used → installed on the movement's ticket issue,
     *               moved → in transit until an in line of the same move
     *               takes them, anything else → damaged_lost;
     *   in lines    bought → new units (carrying the payment line);
     *               moved → the units this move took out, in_stock here;
     *               anything else → an existing unit back in stock, or a
     *               new one.
     *
     * Out lines go first, so a move can take a unit out at A and put it in at
     * B whatever order its lines came in. A refusal is a 422 on the line's
     * serials and rolls the whole movement back.
     *
     * @param  array<int, array<string, mixed>>  $lines
     * @param  array<int, StockMovementLine>  $written
     */
    private function moveUnits(StockMovement $movement, array $lines, array $written): void
    {
        $withSerials = array_filter($lines, fn (array $l) => ! empty($l['serials']));
        if ($withSerials === []) {
            return;
        }

        $type = $movement->type instanceof StockMovementType ? $movement->type : StockMovementType::from((string) $movement->type);
        $inTransit = [];
        $order = array_merge(
            array_keys(array_filter($withSerials, fn (array $l) => (int) $l['direction'] < 0)),
            array_keys(array_filter($withSerials, fn (array $l) => (int) $l['direction'] > 0)),
        );

        foreach ($order as $i) {
            $line = $lines[$i];
            $key = $line['serials_key'] ?? "lines.{$i}.serials";
            $serials = array_values(array_unique(array_map(fn ($v) => trim((string) $v), (array) $line['serials'])));
            $partId = (int) $line['part_id'];
            $locationId = (int) $line['storage_location_id'];
            $partName = Part::withTrashed()->find($partId)?->name;

            if (bccomp($this->scale($line['quantity']), $this->scale(count($serials)), self::SCALE) !== 0) {
                throw ValidationException::withMessages([
                    $key => 'Give one serial number for each piece: '.$this->scale($line['quantity']).' needed, '.count($serials).' given.',
                ]);
            }

            $existing = PartUnit::query()->where('part_id', $partId)->whereIn('serial_number', $serials)->get()->keyBy('serial_number');
            $unitIds = [];

            foreach ($serials as $serial) {
                $unit = $existing->get($serial);

                if ((int) $line['direction'] < 0) {
                    if ($unit === null || $unit->status !== 'in_stock' || (int) $unit->storage_location_id !== $locationId) {
                        $where = StorageLocation::withTrashed()->find($locationId)?->name ?? 'that location';

                        throw ValidationException::withMessages([
                            $key => "Serial {$serial} of {$partName} is not in stock at {$where}.",
                        ]);
                    }

                    $unit->fill(match ($type) {
                        StockMovementType::Used => [
                            'status' => 'installed',
                            'ticket_issue_id' => $movement->ticket_issue_id,
                            'storage_location_id' => null,
                            'installed_at' => $movement->moved_at,
                        ],
                        StockMovementType::Moved => ['storage_location_id' => null],
                        default => ['status' => 'damaged_lost'],
                    })->save();

                    if ($type === StockMovementType::Moved) {
                        $inTransit[$partId][$serial] = $unit;
                    }
                } elseif ($type === StockMovementType::Moved) {
                    if (! isset($inTransit[$partId][$serial])) {
                        throw ValidationException::withMessages([
                            $key => "Serial {$serial} arrives here but leaves nowhere: put it on the line it moves out of too.",
                        ]);
                    }

                    $unit = $inTransit[$partId][$serial];
                    unset($inTransit[$partId][$serial]);
                    $unit->fill(['status' => 'in_stock', 'storage_location_id' => $locationId])->save();
                } else {
                    if ($unit !== null && ($type === StockMovementType::Bought || $unit->status === 'in_stock')) {
                        throw ValidationException::withMessages([
                            $key => $unit->status === 'in_stock'
                                ? "Serial {$serial} of {$partName} is already in stock."
                                : "Serial {$serial} of {$partName} is already recorded.",
                        ]);
                    }

                    $unit ??= new PartUnit([
                        'part_id' => $partId,
                        'serial_number' => $serial,
                        'payment_line_id' => $movement->payment_line_id,
                        'created_by' => Auth::id(),
                    ]);
                    $unit->fill([
                        'status' => 'in_stock',
                        'storage_location_id' => $locationId,
                        'ticket_issue_id' => null,
                        'installed_at' => null,
                    ])->save();
                }

                $unitIds[] = $unit->id;
            }

            $written[$i]->units()->attach($unitIds);
        }

        foreach ($inTransit as $serials) {
            foreach (array_keys($serials) as $serial) {
                throw ValidationException::withMessages([
                    'lines' => "Serial {$serial} leaves but arrives nowhere: put it on the line it moves into too.",
                ]);
            }
        }
    }

    /**
     * Put back the pieces an undone movement moved (called by reverse()):
     *
     *   it took units OUT   → back in stock where they were;
     *   it brought units IN → a unit it created, still on that shelf, is
     *       removed (it never existed); one that came from elsewhere goes
     *       back to what the movement before made it (installed, damaged
     *       or lost, in stock elsewhere); a unit that has moved on since is
     *       left where it is.
     *
     * In lines are undone before out lines, so undoing a move ends with the
     * unit back in stock at the place it left.
     */
    private function unmoveUnits(StockMovement $original, StockMovement $reversal): void
    {
        $original->load('lines.units');
        $reversalLines = $reversal->lines()->orderBy('id')->get()->values();
        $pairs = $original->lines->sortBy('id')->values()
            ->map(fn (StockMovementLine $l, int $i) => [$l, $reversalLines[$i] ?? null])
            ->filter(fn (array $p) => $p[0]->units->isNotEmpty())
            ->sortByDesc(fn (array $p) => $p[0]->direction)
            ->values();

        foreach ($pairs as [$line, $undo]) {
            foreach ($line->units as $unit) {
                if ($line->direction < 0) {
                    $unit->fill([
                        'status' => 'in_stock',
                        'storage_location_id' => $line->storage_location_id,
                        'ticket_issue_id' => null,
                        'installed_at' => null,
                    ])->save();
                    $undo?->units()->syncWithoutDetaching([$unit->id]);

                    continue;
                }

                if ($unit->status !== 'in_stock' || (int) $unit->storage_location_id !== (int) $line->storage_location_id) {
                    continue;
                }

                $previous = StockMovementLine::query()
                    ->with('movement')
                    ->whereHas('units', fn (Builder $q) => $q->whereKey($unit->id))
                    ->where('id', '<', $line->id)
                    ->orderByDesc('id')
                    ->first();

                if ($previous === null) {
                    $unit->delete();

                    continue;
                }

                if ((int) $previous->stock_movement_id !== (int) $original->id) {
                    $before = $previous->movement?->type;
                    $unit->fill(match (true) {
                        $previous->direction < 0 && $before === StockMovementType::Used => [
                            'status' => 'installed',
                            'storage_location_id' => null,
                            'ticket_issue_id' => $previous->movement->ticket_issue_id,
                            'installed_at' => $previous->movement->moved_at,
                        ],
                        $previous->direction < 0 => ['status' => 'damaged_lost', 'storage_location_id' => $previous->storage_location_id],
                        default => ['status' => 'in_stock', 'storage_location_id' => $previous->storage_location_id],
                    })->save();
                }

                $undo?->units()->syncWithoutDetaching([$unit->id]);
            }
        }
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

    /**
     * Write the equal-and-opposite of a movement. The original is left exactly
     * as it was and flagged `mistaken` for display.
     */
    public function reverse(StockMovement $movement): StockMovement
    {
        return DB::transaction(function () use ($movement) {
            $movement->loadMissing('lines');

            // Already reversed: hand back the reversal that exists rather than
            // writing a second one. Without this guard, pressing "mistaken"
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
                'type' => StockMovementType::Undone,
                'storage_location_id' => $movement->storage_location_id,
                'paid_by' => $movement->paid_by,
                'paid_by_technician_id' => $movement->paid_by_technician_id,
                'payment_line_id' => $movement->payment_line_id,
                'ticket_issue_id' => $movement->ticket_issue_id,
                'stock_count_id' => $movement->stock_count_id,
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

            $this->unmoveUnits($movement, $reversal);

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

        if (! empty($filters['ticket_issue_ids'])) {
            $query->whereIn('ticket_issue_id', array_filter((array) $filters['ticket_issue_ids']));
        }

        if (! empty($filters['stock_count_id'])) {
            $query->where('stock_count_id', (int) $filters['stock_count_id']);
        }

        if (! empty($filters['payment_id'])) {
            $query->whereIn('payment_line_id', DB::table('payment_lines')->where('payment_id', (int) $filters['payment_id'])->select('id'));
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
        $query = StockBalance::query()->with(['part', 'storageLocation', 'places.placeLevel', 'places.placeValue']);

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

        /*
         * ?negative_only=1 — only the pairs that owe. Kept INDEPENDENT of
         * non_zero, which hides what netted to zero: conflating the two would
         * let "hide the empties" quietly hide the shortages as well, which are
         * the rows this filter exists to find. Negative implies non-zero, so
         * the two are never in conflict, only ever redundant.
         */
        if (! empty($filters['negative_only'])) {
            $query->where('quantity', '<', 0);
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

        // Also applied to the TOTAL, for the same reason: a part that is -5 on
        // one shelf and +5 on another owes nothing overall, and the shelf that
        // is short is a question for the ungrouped listing, not for this one.
        if (! empty($filters['negative_only'])) {
            $query->havingRaw('SUM(quantity) < 0');
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
            ->with(['storageLocation', 'places.placeLevel', 'places.placeValue'])
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
                        // The row's own id, so the client can address THIS
                        // balance directly -- tagging happens on the stock
                        // list, and the list is built from this shape.
                        'id' => $b->id,
                        'storage_location_id' => $b->storage_location_id,
                        'storage_location' => $b->relationLoaded('storageLocation') && $b->storageLocation
                            ? $this->locations->present($b->storageLocation)
                            : null,
                        'place' => $this->presentPlace($b),
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
     * The average cost of one piece of each part at one location: the FIFO
     * value there divided by the quantity there. Where that location holds
     * nothing to average (none on hand, or no price), the part's average
     * across all locations, then the last price paid, then null.
     *
     * @param  list<int>  $partIds
     * @return array<int, string|null>  keyed by part id, 4 decimals
     */
    public function averageCosts(array $partIds, int $locationId): array
    {
        $valuation = $this->valuePartsFifo($partIds);
        $out = [];

        foreach (array_values(array_unique(array_map('intval', $partIds))) as $partId) {
            $part = $valuation[$partId] ?? null;
            $cost = $this->averageUnitCost($part['locations'][$locationId] ?? null)
                ?? $this->averageUnitCost($part);

            if ($cost === null || bccomp($cost, '0', 4) < 0) {
                $last = DB::table('stock_movement_lines')
                    ->where('part_id', $partId)
                    ->where('direction', 1)
                    ->whereNotNull('unit_cost')
                    ->orderByDesc('id')
                    ->value('unit_cost');
                $cost = $last === null ? null : number_format((float) $last, 4, '.', '');
            }

            $out[$partId] = $cost;
        }

        return $out;
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
            'type_label' => $movement->type->label(),
            'storage_location_id' => $movement->storage_location_id,
            'storage_location' => $movement->relationLoaded('storageLocation') && $movement->storageLocation
                ? $this->locations->present($movement->storageLocation)
                : null,
            // v1 history only: who paid, on movements converted from part usages.
            'paid_by' => $movement->paid_by,
            'paid_by_technician_id' => $movement->paid_by_technician_id,
            'paid_by_technician' => $movement->relationLoaded('paidByTechnician') && $movement->paidByTechnician
                ? $this->catalog->presentTechnician($movement->paidByTechnician)
                : null,
            'body' => $movement->body,
            'payment_line_id' => $movement->payment_line_id,
            'ticket_issue_id' => $movement->ticket_issue_id,
            // The issue the parts were used on (used), when there is one.
            'ticket_issue' => $movement->relationLoaded('ticketIssue') && $movement->ticketIssue ? [
                'id' => $movement->ticketIssue->id,
                'ticket_id' => $movement->ticketIssue->ticket_id,
                'title' => $movement->ticketIssue->displayTitle(),
                'store_number' => $movement->ticketIssue->ticket?->store?->store_number ?? $movement->ticketIssue->ticket?->other_store,
            ] : null,
            // The payment that bought them (bought, and its undo).
            'payment' => $movement->relationLoaded('paymentLine') && $movement->paymentLine?->payment ? [
                'id' => $movement->paymentLine->payment->id,
                'paid_at' => $movement->paymentLine->payment->paid_at?->toDateString(),
                'paid_to' => $movement->paymentLine->payment->paidTo(),
            ] : null,
            'stock_count_id' => $movement->stock_count_id,
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
            // The serial numbers of the pieces this line moved (serial-tracked parts).
            'serials' => $line->relationLoaded('units')
                ? $line->units->pluck('serial_number')->sort()->values()->all()
                : [],
        ];
    }

    /**
     * Say where a part sits inside a location.
     *
     * THE WHOLE ADDRESS AT ONCE. `$valueIds` is the complete set; a level left
     * out of it is cleared. That is not laziness about PATCH semantics -- a
     * part has ONE address per location, so "set shelf to C" and "the address
     * is shelf C and nothing else" have to be distinguishable, and replacing
     * the lot is the only version of that with no hidden state.
     *
     * Every value must belong to a level of THIS balance's location. Otherwise
     * a part in Storage A could be recorded as sitting on Storage B's shelf,
     * which reads as a fact and is not one.
     *
     * @param  array<int, int>  $valueIds  storage_place_value ids, in any order
     * @return array<string, mixed>  the address as presentPlace() renders it
     */
    public function setBalancePlace(StockBalance $balance, array $valueIds): array
    {
        $values = StoragePlaceValue::query()
            ->whereIn('id', $valueIds)
            ->with('placeLevel')
            ->get();

        foreach ($values as $value) {
            if ($value->placeLevel?->storage_location_id !== $balance->storage_location_id) {
                throw ValidationException::withMessages([
                    'place_value_ids' => "That place belongs to a different location.",
                ]);
            }
        }

        // One value per level is a database rule (stock_balance_place_unique),
        // but saying so here gives a sentence instead of an integrity error.
        $byLevel = $values->groupBy('storage_place_level_id');

        foreach ($byLevel as $levelValues) {
            if ($levelValues->count() > 1) {
                throw ValidationException::withMessages([
                    'place_value_ids' => "A part can only be in one place on each level.",
                ]);
            }
        }

        DB::transaction(function () use ($balance, $byLevel) {
            $balance->places()->delete();

            foreach ($byLevel as $levelId => $levelValues) {
                $balance->places()->create([
                    'storage_place_level_id' => $levelId,
                    'storage_place_value_id' => $levelValues->first()->id,
                ]);
            }
        });

        return $this->presentPlace($balance->load(['places.placeLevel', 'places.placeValue']));
    }

    /**
     * The address, ordered the way the location declares its levels.
     *
     * A LIST rather than a map, because the order is the address: "C / 8 / 5"
     * only means shelf-row-column if it comes out in that order every time.
     *
     * @return array<int, array<string, mixed>>
     */
    private function presentPlace(StockBalance $balance): array
    {
        if (! $balance->relationLoaded('places')) {
            return [];
        }

        return $balance->places
            ->filter(fn (StockBalancePlace $p) => $p->placeLevel && $p->placeValue)
            ->sortBy(fn (StockBalancePlace $p) => [$p->placeLevel->sort_order, $p->placeLevel->name])
            ->map(fn (StockBalancePlace $p) => [
                'level_id' => $p->storage_place_level_id,
                'level' => $p->placeLevel->name,
                'value_id' => $p->storage_place_value_id,
                'value' => $p->placeValue->value,
            ])
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    public function presentBalance(StockBalance $balance, ?array $valuation = null): array
    {
        return [
            'id' => $balance->id,
            'part_id' => $balance->part_id,
            'part' => $balance->relationLoaded('part') && $balance->part
                ? $this->catalog->presentPart($balance->part)
                : null,
            'storage_location_id' => $balance->storage_location_id,
            'storage_location' => $balance->relationLoaded('storageLocation') && $balance->storageLocation
                ? $this->locations->present($balance->storageLocation)
                : null,
            // Where inside that location it sits, level by level. An EMPTY
            // list means nobody has said -- which is different from "nowhere",
            // so do not substitute a dash for it downstream.
            'place' => $this->presentPlace($balance),
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
