<?php

namespace App\Services;

use App\Enums\StockMovementType;
use App\Models\StockBalance;
use App\Models\StockCount;
use App\Models\StockCountLine;
use App\Models\StoragePlaceValue;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Stock counts (docs/MAINTENANCE.md §5): a sheet for one storage location
 * (or one place in it) listing every part with what the system expects;
 * somebody types what is really there; applying it writes ONE stock_count
 * movement for the differences and freezes the values.
 *
 *   draft     expected = the balance when the sheet was made; counted typed in
 *             (null = not counted yet); values shown at today's average cost.
 *   applied   expected recomputed at the moment of applying (stock may have
 *             moved since the sheet was made), the difference written to the
 *             ledger, each line's unit cost frozen. Kept as the report.
 *
 * Lines nobody counted are left out of the movement: "not counted" is not
 * "zero". The quantity is per location -- a place only chooses which parts
 * are on the sheet, because a balance is kept per location.
 */
class StockCountService
{
    private const SCALE = 2;

    private const LOADS = ['storageLocation', 'placeValue.placeLevel', 'lines.part', 'movement', 'creator', 'applier'];

    public function __construct(
        private StockService $stock,
        private CatalogService $catalog,
    ) {}

    /**
     * @param  array<string, mixed>  $filters  storage_location_id, status
     */
    public function list(array $filters, int $perPage): LengthAwarePaginator
    {
        return StockCount::query()
            ->with(self::LOADS)
            ->when(! empty($filters['storage_location_id']), fn ($q) => $q->where('storage_location_id', (int) $filters['storage_location_id']))
            ->when(! empty($filters['status']), fn ($q) => $q->where('status', (string) $filters['status']))
            ->orderByDesc('started_at')
            ->orderByDesc('id')
            ->paginate($perPage)
            ->through(fn (StockCount $c) => $this->present($c, false));
    }

    /**
     * A new draft with one line per part that has stock at the location (or
     * at that place in it).
     */
    public function create(int $locationId, ?int $placeValueId, ?string $notes): StockCount
    {
        if ($placeValueId !== null) {
            $value = StoragePlaceValue::query()->with('placeLevel')->find($placeValueId);
            if ($value === null || (int) $value->placeLevel?->storage_location_id !== $locationId) {
                throw ValidationException::withMessages([
                    'storage_place_value_id' => 'That place is not in this storage location.',
                ]);
            }
        }

        return DB::transaction(function () use ($locationId, $placeValueId, $notes) {
            $count = new StockCount([
                'storage_location_id' => $locationId,
                'storage_place_value_id' => $placeValueId,
                'status' => 'draft',
                'started_at' => now(),
                'notes_text' => $notes,
            ]);
            $count->created_by = Auth::id();
            $count->save();

            $balances = StockBalance::query()
                ->where('storage_location_id', $locationId)
                ->where('quantity', '!=', 0)
                ->when($placeValueId !== null, fn ($q) => $q->whereHas(
                    'places',
                    fn ($p) => $p->where('storage_place_value_id', $placeValueId)
                ))
                ->orderBy('part_id')
                ->get();

            foreach ($balances as $balance) {
                $count->lines()->create([
                    'part_id' => $balance->part_id,
                    'expected' => $this->scale($balance->quantity),
                    'counted' => null,
                ]);
            }

            return $count;
        });
    }

    /**
     * Type in what was counted (null = not counted), adding parts that are
     * not on the sheet yet (expected = their balance here now).
     *
     * @param  array<int, array{part_id: int|string, counted: mixed}>  $lines
     */
    public function update(StockCount $count, array $lines, bool $notesSent, ?string $notes): StockCount
    {
        $this->assertDraft($count);

        DB::transaction(function () use ($count, $lines, $notesSent, $notes) {
            foreach ($lines as $line) {
                $partId = (int) $line['part_id'];
                $counted = array_key_exists('counted', $line) && $line['counted'] !== null && $line['counted'] !== ''
                    ? $this->scale($line['counted'])
                    : null;

                $row = $count->lines()->where('part_id', $partId)->first();

                if ($row === null) {
                    $count->lines()->create([
                        'part_id' => $partId,
                        'expected' => $this->balance($partId, $count->storage_location_id),
                        'counted' => $counted,
                    ]);

                    continue;
                }

                $row->update(['counted' => $counted]);
            }

            if ($notesSent) {
                $count->notes_text = $notes;
            }

            $count->touch();
            $count->save();
        });

        return $count;
    }

    /**
     * Write the differences as one stock_count movement and freeze the sheet.
     */
    public function apply(StockCount $count): StockCount
    {
        $this->assertDraft($count);

        return DB::transaction(function () use ($count) {
            $count = StockCount::query()->lockForUpdate()->findOrFail($count->id);
            $this->assertDraft($count);

            $lines = $count->lines()->orderBy('id')->get();
            if ($lines->every(fn (StockCountLine $l) => $l->counted === null)) {
                throw ValidationException::withMessages([
                    'lines' => 'Nothing has been counted yet: type in at least one count before applying.',
                ]);
            }

            $locationId = (int) $count->storage_location_id;
            $costs = $this->stock->averageCosts($lines->pluck('part_id')->all(), $locationId);
            $movementLines = [];
            $missing = '0.00';
            $extra = '0.00';

            foreach ($lines as $line) {
                // Stock may have moved since the sheet was made: the difference
                // is against what is there NOW.
                $expected = $this->balance((int) $line->part_id, $locationId, lock: true);
                $cost = $costs[$line->part_id] ?? null;
                $line->expected = $expected;
                $line->unit_cost = $cost;
                $line->save();

                if ($line->counted === null) {
                    continue;
                }

                $difference = bcsub($this->scale($line->counted), $expected, self::SCALE);
                $sign = bccomp($difference, '0', self::SCALE);
                if ($sign === 0) {
                    continue;
                }

                $quantity = ltrim($difference, '-');
                $value = $cost === null ? '0.00' : bcmul($quantity, $cost, self::SCALE);
                if ($sign < 0) {
                    $missing = bcadd($missing, $value, self::SCALE);
                } else {
                    $extra = bcadd($extra, $value, self::SCALE);
                }

                $movementLines[] = [
                    'part_id' => (int) $line->part_id,
                    'storage_location_id' => $locationId,
                    'quantity' => $quantity,
                    'direction' => $sign,
                    'unit_cost' => $cost,
                ];
            }

            if ($movementLines !== []) {
                $this->stock->record([
                    'moved_at' => now(),
                    'type' => StockMovementType::StockCount,
                    'storage_location_id' => $locationId,
                    'stock_count_id' => $count->id,
                    'body' => "Stock count #{$count->id}.",
                ], $movementLines);
            }

            $count->fill([
                'status' => 'applied',
                'applied_at' => now(),
                'applied_by' => Auth::id(),
                'missing_value' => $missing,
                'extra_value' => $extra,
            ])->save();

            return $count;
        });
    }

    public function delete(StockCount $count): void
    {
        if (! $count->isDraft()) {
            throw ValidationException::withMessages([
                'status' => 'An applied stock count is kept as the report and cannot be deleted.',
            ]);
        }

        $count->delete();
    }

    /**
     * @return array<string, mixed>
     */
    public function show(StockCount $count): array
    {
        return $this->present($count->load(self::LOADS), true);
    }

    /**
     * @return array<string, mixed>
     */
    public function present(StockCount $count, bool $withLines): array
    {
        $count->loadMissing(self::LOADS);
        $draft = $count->isDraft();
        $costs = $draft
            ? $this->stock->averageCosts($count->lines->pluck('part_id')->all(), (int) $count->storage_location_id)
            : [];

        $totals = [
            'missing_quantity' => '0.00',
            'missing_value' => '0.00',
            'extra_quantity' => '0.00',
            'extra_value' => '0.00',
            'counted_lines' => 0,
            'total_lines' => $count->lines->count(),
        ];

        $lines = $count->lines->sortBy(fn (StockCountLine $l) => mb_strtolower((string) $l->part?->name))->values()
            ->map(function (StockCountLine $line) use ($draft, $costs, &$totals) {
                $cost = $draft ? ($costs[$line->part_id] ?? null) : ($line->unit_cost === null ? null : number_format((float) $line->unit_cost, 4, '.', ''));
                $difference = $line->difference();
                $valueDifference = $difference === null || $cost === null ? null : bcmul($difference, $cost, self::SCALE);

                if ($difference !== null) {
                    $totals['counted_lines']++;
                    $sign = bccomp($difference, '0', self::SCALE);
                    $abs = ltrim($difference, '-');
                    $absValue = $valueDifference === null ? '0.00' : ltrim($valueDifference, '-');
                    if ($sign < 0) {
                        $totals['missing_quantity'] = bcadd($totals['missing_quantity'], $abs, self::SCALE);
                        $totals['missing_value'] = bcadd($totals['missing_value'], $absValue, self::SCALE);
                    } elseif ($sign > 0) {
                        $totals['extra_quantity'] = bcadd($totals['extra_quantity'], $abs, self::SCALE);
                        $totals['extra_value'] = bcadd($totals['extra_value'], $absValue, self::SCALE);
                    }
                }

                return [
                    'id' => $line->id,
                    'part_id' => $line->part_id,
                    'part' => $line->part ? ['id' => $line->part->id, 'name' => $line->part->name, 'unit' => $line->part->unit ?? 'each'] : null,
                    'expected' => $this->scale($line->expected),
                    'counted' => $line->counted === null ? null : $this->scale($line->counted),
                    'difference' => $difference,
                    'unit_cost' => $cost,
                    'value_difference' => $valueDifference,
                ];
            })->all();

        $value = $count->placeValue;

        return [
            'id' => $count->id,
            'storage_location_id' => $count->storage_location_id,
            'storage_location' => $count->storageLocation ? ['id' => $count->storageLocation->id, 'name' => $count->storageLocation->name] : null,
            'storage_place_value_id' => $count->storage_place_value_id,
            'storage_place_value' => $value ? [
                'id' => $value->id,
                'value' => $value->value,
                'level_id' => $value->storage_place_level_id,
                'level' => $value->placeLevel?->name,
            ] : null,
            'status' => $count->status,
            'started_at' => $count->started_at,
            'applied_at' => $count->applied_at,
            'applied_by' => $count->applied_by,
            'applier' => $count->applier ? $this->catalog->presentUser($count->applier) : null,
            'notes_text' => $count->notes_text,
            'stock_movement_id' => $count->movement?->id,
            'lines' => $withLines ? $lines : null,
            'totals' => $totals,
            'created_by' => $count->created_by,
            'creator' => $count->creator ? $this->catalog->presentUser($count->creator) : null,
            'created_at' => $count->created_at,
            'updated_at' => $count->updated_at,
        ];
    }

    private function assertDraft(StockCount $count): void
    {
        if (! $count->isDraft()) {
            throw ValidationException::withMessages([
                'status' => 'This stock count is already applied; start a new count to change anything.',
            ]);
        }
    }

    private function balance(int $partId, int $locationId, bool $lock = false): string
    {
        $value = StockBalance::query()
            ->where('part_id', $partId)
            ->where('storage_location_id', $locationId)
            ->when($lock, fn ($q) => $q->lockForUpdate())
            ->value('quantity');

        return $this->scale($value ?? 0);
    }

    private function scale(mixed $value): string
    {
        return number_format((float) $value, self::SCALE, '.', '');
    }
}
