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
        $query = StockBalance::query()->with(['part', 'storageLocation']);

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

        return $query->orderBy('part_id')->orderBy('storage_location_id')
            ->paginate(max(1, (int) ($filters['per_page'] ?? 50)))
            ->through(fn (StockBalance $b) => $this->presentBalance($b));
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
    public function presentBalance(StockBalance $balance): array
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
            'quantity' => $balance->quantity,
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
