<?php

namespace App\Services;

use App\Models\Category;
use App\Models\Issue;
use App\Models\Part;
use App\Models\PartUnit;
use App\Models\Store;
use App\Models\Technician;
use App\Models\TechnicianIssueAbility;
use App\Models\User;
use App\Support\NameKey;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * All controlled reference catalogs (issues, technicians, categories, parts):
 * listing with the ?trashed flag, creation, soft/hard delete, restore, and
 * presentation. Standalone — depends on no other service.
 */
class CatalogService
{
    /**
     * Loaded on every read that presents a catalog item. The presenters always
     * emitted notes and attachments, but nothing loaded them, so a note added
     * to an issue or technician saved fine and then came back as `null` on the
     * next list -- it looked lost.
     */
    private const CATALOG_LOADS = [
        'creator',
        'notes.creator',
        'notes.attachments.creator',
        'attachments.creator',
    ];

    /**
     * Issues also say how many troubleshooting guides they have -- enough for
     * the new-ticket window to know it must show them; the guides themselves
     * are read from the issue's troubleshooting page.
     */
    private const ISSUE_LOADS = self::CATALOG_LOADS;

    public function __construct(
        private NoteService $notes,
        private AttachmentService $attachments,
        private TechnicianAbilityService $abilities,
    ) {}

    // ------------------------------------------------------------------ Issues

    /**
     * Only guides with steps count: one without asks nothing of the store.
     *
     * @return array<string, \Closure>
     */
    private function guideCount(): array
    {
        return ['troubleshootingGuides' => fn ($q) => $q->whereHas('steps')];
    }

    public function listIssues(?string $trashed, int $perPage): LengthAwarePaginator
    {
        $query = $this->trashed(Issue::query()->with(self::ISSUE_LOADS)->withCount($this->guideCount())->latest(), $trashed);

        return $query->paginate($perPage)->through(fn (Issue $i) => $this->presentIssue($i));
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function createIssue(array $data): array
    {
        return $this->presentIssue($this->persist(new Issue($data))->load(self::ISSUE_LOADS)->loadCount($this->guideCount()));
    }

    public function deleteIssue(Issue $issue): void
    {
        $issue->delete();
    }

    /**
     * @return array<string, mixed>
     */
    public function restoreIssue(Issue $issue): array
    {
        $issue->restore();

        return $this->presentIssue($issue->load(self::ISSUE_LOADS)->loadCount($this->guideCount()));
    }

    /**
     * @param  array<string, mixed>  $data  Only the fields being changed.
     * @return array<string, mixed>
     */
    public function updateIssue(Issue $issue, array $data): array
    {
        $issue->fill($data)->save();

        return $this->presentIssue($issue->load(self::ISSUE_LOADS)->loadCount($this->guideCount()));
    }

    // ------------------------------------------------------------- Technicians

    /**
     * A technician is shown with their category, the stores they cover and
     * their per-issue abilities (for the average rating and the count).
     */
    private const TECHNICIAN_LOADS = ['category', 'coverageStores', 'abilities', ...self::CATALOG_LOADS];

    /**
     * The technician list and the pickers (docs/MAINTENANCE.md §1).
     *
     * Filters: `search` (name, phone, contact person), `category_id`,
     * `emergency=1`, `with_trashed=1` (or the older `trashed=with|only`).
     *
     * `store` (a store number) and `issue_id` narrow the list to the people
     * who cover them -- covering EITHER when both are given, because the rank
     * order then puts those covering both first -- and turn on the RANKING:
     *
     *   covers the store → covers the issue → emergency (only when urgent=1)
     *   → "call first" on that issue → rating on that issue (unrated last)
     *   → name
     *
     * Ranked rows add `standing {covers_store, covers_issue, call_first,
     * rating}`; a key whose question was not asked is null.
     *
     * The ranking is done in SQL, so pagination stays correct.
     *
     * Every row adds `paid_this_month`: payments to them that are not
     * mistaken, paid_at in the current calendar month -- one aggregate
     * subquery, never a query per row.
     *
     * @param  array<string, mixed>  $filters
     */
    public function listTechnicians(array $filters, int $perPage): LengthAwarePaginator
    {
        $trashed = ! empty($filters['with_trashed']) ? 'with' : ($filters['trashed'] ?? null);
        $query = $this->trashed(Technician::query()->with(self::TECHNICIAN_LOADS), $trashed);

        $search = trim((string) ($filters['search'] ?? ''));
        if ($search !== '') {
            $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $search).'%';
            $query->where(fn (Builder $q) => $q
                ->where('name', 'like', $like)
                ->orWhere('phone', 'like', $like)
                ->orWhere('contact_person', 'like', $like));
        }

        if (! empty($filters['category_id'])) {
            $query->where('category_id', (int) $filters['category_id']);
        }

        if (! empty($filters['emergency']) && filter_var($filters['emergency'], FILTER_VALIDATE_BOOLEAN)) {
            $query->where('emergency_available', true);
        }

        // stores[]: covers ANY of these stores (the page's store checklist). One
        // store picked is sent as `store` instead, which also ranks the list.
        $stores = array_values(array_filter(array_map('strval', (array) ($filters['stores'] ?? []))));
        if ($stores !== []) {
            $query->whereHas('coverageStores', fn ($q) => $q->whereIn('stores.store_number', $stores));
        }

        $store = isset($filters['store']) && $filters['store'] !== '' ? (string) $filters['store'] : null;
        $issueId = ! empty($filters['issue_id']) ? (int) $filters['issue_id'] : null;
        $urgent = ! empty($filters['urgent']) && filter_var($filters['urgent'], FILTER_VALIDATE_BOOLEAN);
        $ranking = $store !== null || $issueId !== null;

        if (! $ranking) {
            return $this->withPaidThisMonth($query)->latest()->paginate($perPage)
                ->through(fn (Technician $t) => $this->presentTechnician($t) + $this->paidThisMonth($t));
        }

        $coversStore = fn ($q) => $q->selectRaw('1')
            ->from('store_technician')
            ->join('stores', 'stores.id', '=', 'store_technician.store_id')
            ->whereColumn('store_technician.technician_id', 'technicians.id')
            ->where('stores.store_number', $store);
        $coversIssue = fn ($q) => $q->selectRaw('1')
            ->from('technician_issue_abilities')
            ->whereColumn('technician_issue_abilities.technician_id', 'technicians.id')
            ->where('technician_issue_abilities.issue_id', $issueId);

        $query->where(function (Builder $q) use ($store, $issueId, $coversStore, $coversIssue) {
            if ($store !== null) {
                $q->orWhereExists($coversStore);
            }
            if ($issueId !== null) {
                $q->orWhereExists($coversIssue);
            }
        });

        $query->select('technicians.*');
        $query->selectRaw(
            $store !== null
                ? '(CASE WHEN EXISTS (SELECT 1 FROM store_technician INNER JOIN stores ON stores.id = store_technician.store_id WHERE store_technician.technician_id = technicians.id AND stores.store_number = ?) THEN 1 ELSE 0 END) AS rank_covers_store'
                : '0 AS rank_covers_store',
            $store !== null ? [$store] : [],
        );
        // Every rank column is already a plain number, so ORDER BY only ever
        // names an alias -- never an expression over one, which not every
        // database accepts.
        $ability = fn (string $expr, string $alias) => $query->selectRaw(
            $issueId !== null
                ? "({$expr}) AS {$alias}"
                : "0 AS {$alias}",
            $issueId !== null ? [$issueId] : [],
        );
        $row = 'FROM technician_issue_abilities a WHERE a.technician_id = technicians.id AND a.issue_id = ?';
        $ability("CASE WHEN EXISTS (SELECT 1 {$row}) THEN 1 ELSE 0 END", 'rank_covers_issue');
        $ability("SELECT CASE WHEN a.call_first = 1 THEN 1 ELSE 0 END {$row} LIMIT 1", 'rank_call_first');
        $ability("SELECT CASE WHEN a.rating IS NULL THEN 0 ELSE 1 END {$row} LIMIT 1", 'rank_rated');
        $ability("SELECT a.rating {$row} LIMIT 1", 'rank_rating');

        $query->orderByDesc('rank_covers_store')->orderByDesc('rank_covers_issue');
        if ($urgent) {
            $query->orderByDesc('emergency_available');
        }
        $query->orderByDesc('rank_call_first')
            ->orderByDesc('rank_rated')
            ->orderByDesc('rank_rating')
            ->orderBy('name')
            ->orderBy('technicians.id');

        // After select('technicians.*'), which would otherwise drop the sum.
        $this->withPaidThisMonth($query);

        return $query->paginate($perPage)->through(fn (Technician $t) => $this->presentTechnician($t) + $this->paidThisMonth($t) + [
            'standing' => [
                'covers_store' => $store !== null ? (bool) $t->rank_covers_store : null,
                'covers_issue' => $issueId !== null ? (bool) $t->rank_covers_issue : null,
                'call_first' => $issueId !== null ? (bool) $t->rank_call_first : null,
                'rating' => $issueId !== null && $t->rank_rating !== null ? (int) $t->rank_rating : null,
            ],
        ]);
    }

    /**
     * Sum this month's payments onto each technician row (`paid_this_month`).
     * paid_at is a date: the month is [the 1st, the 1st of next month).
     *
     * @param  Builder<Technician>  $query
     * @return Builder<Technician>
     */
    private function withPaidThisMonth(Builder $query): Builder
    {
        $from = now()->startOfMonth()->toDateString();
        $until = now()->startOfMonth()->addMonth()->toDateString();

        return $query->withSum(['payments as paid_this_month' => fn ($q) => $q
            ->where('mistaken', false)
            ->where('paid_at', '>=', $from)
            ->where('paid_at', '<', $until)], 'total');
    }

    /**
     * @return array{paid_this_month: string}
     */
    private function paidThisMonth(Technician $technician): array
    {
        return ['paid_this_month' => number_format((float) $technician->paid_this_month, 2, '.', '')];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function createTechnician(array $data): array
    {
        $technician = DB::transaction(function () use ($data) {
            $technician = $this->persist(new Technician($this->tidyName($data)));
            $this->syncCoverage($technician, $data);

            return $technician;
        });

        return $this->showTechnician($technician);
    }

    /**
     * One technician, with everything the technician page shows about them.
     *
     * @return array<string, mixed>
     */
    public function showTechnician(Technician $technician): array
    {
        return $this->presentTechnician($technician->load([...self::TECHNICIAN_LOADS, 'abilities.issue']), true);
    }

    /**
     * Coverage is the whole list of stores, replaced when sent.
     *
     * @param  array<string, mixed>  $data
     */
    private function syncCoverage(Technician $technician, array $data): void
    {
        if (!array_key_exists('coverage_stores', $data)) {
            return;
        }

        $ids = Store::query()->whereIn('store_number', (array) $data['coverage_stores'])->pluck('id')->all();
        $technician->coverageStores()->sync($ids);
    }

    /**
     * Soft delete. Their "call first" pins go with them (see
     * TechnicianAbilityService::clearPins); stars and notes stay for a restore.
     */
    public function deleteTechnician(Technician $technician): void
    {
        DB::transaction(function () use ($technician) {
            $this->abilities->clearPins($technician);
            $technician->delete();
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function restoreTechnician(Technician $technician): array
    {
        $technician->restore();

        return $this->showTechnician($technician);
    }

    /**
     * @param  array<string, mixed>  $data  Only the fields being changed.
     * @return array<string, mixed>
     */
    public function updateTechnician(Technician $technician, array $data): array
    {
        DB::transaction(function () use ($technician, $data) {
            $technician->fill($this->tidyName($data))->save();
            $this->syncCoverage($technician, $data);
        });

        return $this->showTechnician($technician);
    }

    // -------------------------------------------------------------- Categories

    public function listCategories(int $perPage): LengthAwarePaginator
    {
        return Category::query()->with(self::CATALOG_LOADS)->withCount('technicians')->latest()
            ->paginate($perPage)->through(fn (Category $c) => $this->presentCategory($c));
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function createCategory(array $data): array
    {
        return $this->presentCategory($this->persist(new Category($this->tidyName($data)))->load(self::CATALOG_LOADS));
    }

    /**
     * @param  array<string, mixed>  $data  Only the fields being changed.
     * @return array<string, mixed>
     */
    public function updateCategory(Category $category, array $data): array
    {
        $category->fill($this->tidyName($data))->save();

        return $this->presentCategory($category->load(self::CATALOG_LOADS)->loadCount('technicians'));
    }

    public function deleteCategory(Category $category): void
    {
        // Hard delete; the FK is nullOnDelete so its technicians survive.
        $category->delete();
    }

    // ------------------------------------------------------------------- Parts

    /**
     * The parts list and the part pickers.
     *
     * `search` matches the name, brand, model or part number, ignoring
     * capitals (compared lower-cased, so it does not hang on the database's
     * collation). `trashed=with|only` as everywhere else.
     */
    public function listParts(?string $trashed, int $perPage, ?string $search = null): LengthAwarePaginator
    {
        $query = $this->trashed(Part::query()->with(self::CATALOG_LOADS)->latest(), $trashed);

        $search = mb_strtolower(trim((string) $search));
        if ($search !== '') {
            $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $search).'%';
            $query->where(fn (Builder $q) => $q
                ->whereRaw('LOWER(name) LIKE ?', [$like])
                ->orWhereRaw('LOWER(brand) LIKE ?', [$like])
                ->orWhereRaw('LOWER(model) LIKE ?', [$like])
                ->orWhereRaw('LOWER(part_number) LIKE ?', [$like]));
        }

        return $query->paginate($perPage)->through(fn (Part $p) => $this->presentPart($p));
    }

    /**
     * One part: the list row plus how many pieces of it are recorded
     * (`units_count`, every status -- only serial-tracked parts have any).
     *
     * @return array<string, mixed>
     */
    public function showPart(Part $part): array
    {
        $part->load(self::CATALOG_LOADS)->loadCount('units');

        return $this->presentPart($part) + [
            'units_count' => (int) $part->units_count,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function createPart(array $data): array
    {
        return $this->presentPart($this->persist(new Part($this->tidyName($data)))->load(self::CATALOG_LOADS));
    }

    public function deletePart(Part $part): void
    {
        $part->delete();
    }

    /**
     * @return array<string, mixed>
     */
    public function restorePart(Part $part): array
    {
        $part->restore();

        return $this->presentPart($part->load(self::CATALOG_LOADS));
    }

    /**
     * @param  array<string, mixed>  $data  Only the fields being changed.
     * @return array<string, mixed>
     */
    public function updatePart(Part $part, array $data): array
    {
        $part->fill($this->tidyName($data))->save();

        return $this->presentPart($part->load(self::CATALOG_LOADS));
    }

    /**
     * The individual pieces of a serial-tracked part, newest first.
     * `status` narrows to one of PartUnit::STATUSES.
     *
     * @return array<int, array<string, mixed>>
     */
    public function listPartUnits(Part $part, ?string $status): array
    {
        return $part->units()
            ->with(self::PART_UNIT_LOADS)
            ->when($status !== null && $status !== '', fn ($q) => $q->where('status', $status))
            ->orderByDesc('id')
            ->get()
            ->map(fn (PartUnit $u) => $this->presentPartUnit($u))
            ->all();
    }

    /**
     * Correct a unit's serial number or warranty date.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function updatePartUnit(PartUnit $unit, array $data): array
    {
        $unit->fill(array_intersect_key($data, array_flip(['serial_number', 'warranty_until'])))->save();

        return $this->presentPartUnit($unit->load(self::PART_UNIT_LOADS));
    }

    public const PART_UNIT_LOADS = ['storageLocation', 'ticketIssue.ticket.store', 'ticketIssue.issue'];

    // ------------------------------------------------------------- Presenters

    /**
     * @return array<string, mixed>
     */
    public function presentPartUnit(PartUnit $unit): array
    {
        $issue = $unit->relationLoaded('ticketIssue') ? $unit->ticketIssue : null;

        return [
            'id' => $unit->id,
            'part_id' => $unit->part_id,
            'serial_number' => $unit->serial_number,
            'status' => $unit->status,
            'storage_location' => $unit->relationLoaded('storageLocation') && $unit->storageLocation
                ? ['id' => $unit->storageLocation->id, 'name' => $unit->storageLocation->name]
                : null,
            'ticket_issue' => $issue === null ? null : [
                'id' => $issue->id,
                'ticket_id' => $issue->ticket_id,
                'store_number' => $issue->ticket?->store?->store_number ?? $issue->ticket?->other_store,
                'title' => $issue->displayTitle(),
            ],
            'payment_line_id' => $unit->payment_line_id,
            'installed_at' => $unit->installed_at,
            'warranty_until' => $unit->warranty_until?->toDateString(),
            'created_at' => $unit->created_at,
            'updated_at' => $unit->updated_at,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function presentIssue(Issue $issue): array
    {
        return [
            'id' => $issue->id,
            'title' => $issue->title,
            'description' => $issue->description,
            // Guides to go through before opening a ticket for it (null when
            // not counted on this read).
            'troubleshooting_guides_count' => array_key_exists('troubleshooting_guides_count', $issue->getAttributes())
                ? (int) $issue->troubleshooting_guides_count
                : null,
            'notes' => $this->notes->presentMany($issue),
            'attachments' => $this->attachments->presentMany($issue),
            'created_by' => $issue->created_by,
            'creator' => $issue->relationLoaded('creator') && $issue->creator
                ? $this->presentUser($issue->creator)
                : null,
            'created_at' => $issue->created_at,
            'updated_at' => $issue->updated_at,
            'deleted_at' => $issue->deleted_at,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function presentTechnician(Technician $technician, bool $withCoveredIssues = false): array
    {
        return [
            'id' => $technician->id,
            'name' => $technician->name,
            'phone' => $technician->phone,
            'contact_person' => $technician->contact_person,
            'hourly_rate' => $technician->hourly_rate === null ? null : number_format((float) $technician->hourly_rate, 2, '.', ''),
            'emergency_available' => (bool) $technician->emergency_available,
            'emergency_notes' => $technician->emergency_notes,
            // Always null for now: technicians have no logins yet.
            'user_id' => $technician->user_id,
            'location' => $technician->location,
            'coverage_notes' => $technician->coverage_notes,
            // The stores they can cover (null when not loaded on this read).
            'coverage_stores' => $technician->relationLoaded('coverageStores')
                ? $technician->coverageStores->map(fn (Store $s) => ['id' => $s->id, 'store_number' => $s->store_number])->values()->all()
                : null,
            // The average of the per-issue ratings (the only rating there is).
            'average_rating' => $technician->relationLoaded('abilities') ? $technician->averageRating() : null,
            'covered_issue_count' => $technician->relationLoaded('abilities') ? $technician->abilities->count() : null,
            // The issues they cover, with the rating, notes and "call first"
            // pin on each -- on the technician page only (show).
            ...($withCoveredIssues && $technician->relationLoaded('abilities') ? [
                'covered_issues' => $technician->abilities
                    ->sortBy(fn (TechnicianIssueAbility $a) => mb_strtolower((string) $a->issue?->title))
                    ->map(fn (TechnicianIssueAbility $a) => [
                        'issue_id' => $a->issue_id,
                        'title' => $a->issue?->title,
                        'rating' => $a->rating,
                        'notes' => $a->notes,
                        'call_first' => (bool) $a->call_first,
                    ])->values()->all(),
            ] : []),
            'category_id' => $technician->category_id,
            'category' => $technician->relationLoaded('category') && $technician->category
                ? $this->presentCategory($technician->category)
                : null,
            'notes' => $this->notes->presentMany($technician),
            'attachments' => $this->attachments->presentMany($technician),
            'created_by' => $technician->created_by,
            'creator' => $technician->relationLoaded('creator') && $technician->creator
                ? $this->presentUser($technician->creator)
                : null,
            'created_at' => $technician->created_at,
            'updated_at' => $technician->updated_at,
            'deleted_at' => $technician->deleted_at,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function presentCategory(Category $category): array
    {
        return [
            'id' => $category->id,
            'name' => $category->name,
            'description' => $category->description,
            'technicians_count' => $category->technicians_count ?? null,
            'notes' => $this->notes->presentMany($category),
            'attachments' => $this->attachments->presentMany($category),
            'created_by' => $category->created_by,
            'creator' => $category->relationLoaded('creator') && $category->creator
                ? $this->presentUser($category->creator)
                : null,
            'created_at' => $category->created_at,
            'updated_at' => $category->updated_at,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function presentPart(Part $part): array
    {
        return [
            'id' => $part->id,
            'name' => $part->name,
            'description' => $part->description,
            'brand' => $part->brand,
            'model' => $part->model,
            'part_number' => $part->part_number,
            'unit' => $part->unit ?? 'each',
            'track_serials' => (bool) $part->track_serials,
            'notes' => $this->notes->presentMany($part),
            'attachments' => $this->attachments->presentMany($part),
            'created_by' => $part->created_by,
            'creator' => $part->relationLoaded('creator') && $part->creator
                ? $this->presentUser($part->creator)
                : null,
            'created_at' => $part->created_at,
            'updated_at' => $part->updated_at,
            'deleted_at' => $part->deleted_at,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function presentUser(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
        ];
    }

    // ---------------------------------------------------------------- Helpers

    /**
     * Names are stored tidied (trimmed, inner spaces collapsed), the form the
     * uniqueness rule compares. The models do it again on save; doing it here
     * too keeps what the service hands them already in its final form.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function tidyName(array $data): array
    {
        if (array_key_exists('name', $data) && is_string($data['name'])) {
            $data['name'] = NameKey::tidy($data['name']);
        }

        return $data;
    }

    /**
     * Stamp the acting user and save.
     *
     * @template T of \Illuminate\Database\Eloquent\Model
     *
     * @param  T  $model
     * @return T
     */
    private function persist($model)
    {
        $model->created_by = Auth::id();
        $model->save();

        return $model;
    }

    /**
     * Apply the ?trashed=with|only flag to a soft-deletable query.
     *
     * @param  Builder<Model>  $query
     * @return Builder<Model>
     */
    private function trashed(Builder $query, ?string $trashed): Builder
    {
        return match ($trashed) {
            'with' => $query->withTrashed(),
            'only' => $query->onlyTrashed(),
            default => $query,
        };
    }
}
