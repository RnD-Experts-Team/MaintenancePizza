<?php

namespace App\Services;

use App\Models\Supplier;
use App\Support\NameKey;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

/**
 * Suppliers: the places we buy from (docs/MAINTENANCE.md §1). Names are
 * unique ignoring capitals and repeated spaces, soft-deleted ones included
 * (HasUniqueName + the UniqueName rule).
 */
class SupplierService
{
    private const LOADS = [
        'creator',
        'notes.creator',
        'notes.attachments.creator',
        'attachments.creator',
    ];

    public function __construct(
        private NoteService $notes,
        private AttachmentService $attachments,
        private CatalogService $catalog,
    ) {}

    /**
     * `search` matches name, phone and website; `with_trashed=1` includes
     * deleted ones. Alphabetical. Each row carries `total_paid`, summed in
     * the same query (one aggregate subquery, never a query per row).
     *
     * @param  array<string, mixed>  $filters
     */
    public function list(array $filters, int $perPage): LengthAwarePaginator
    {
        $query = Supplier::query()->with(self::LOADS);

        if (! empty($filters['with_trashed']) && filter_var($filters['with_trashed'], FILTER_VALIDATE_BOOLEAN)) {
            $query->withTrashed();
        }

        $search = trim((string) ($filters['search'] ?? ''));
        if ($search !== '') {
            $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $search).'%';
            $query->where(fn (Builder $q) => $q
                ->where('name', 'like', $like)
                ->orWhere('phone', 'like', $like)
                ->orWhere('website', 'like', $like));
        }

        return $this->withTotalPaid($query)->orderBy('name')->orderBy('id')
            ->paginate($perPage)
            ->through(fn (Supplier $s) => $this->present($s) + ['total_paid' => $this->money($s->total_paid)]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): Supplier
    {
        $supplier = new Supplier($this->tidy($data));
        $supplier->created_by = Auth::id();
        $supplier->save();

        return $supplier;
    }

    /**
     * @param  array<string, mixed>  $data  Only the fields being changed.
     */
    public function update(Supplier $supplier, array $data): Supplier
    {
        $supplier->fill($this->tidy($data))->save();

        return $supplier;
    }

    public function delete(Supplier $supplier): void
    {
        $supplier->delete();
    }

    public function restore(Supplier $supplier): Supplier
    {
        $supplier->restore();

        return $supplier;
    }

    /**
     * One supplier with what we have paid them directly (non-mistaken
     * payments to the supplier).
     *
     * @return array<string, mixed>
     */
    public function show(Supplier $supplier): array
    {
        $supplier->load(self::LOADS);

        $total = (string) $supplier->payments()->where('mistaken', false)->sum('total');

        return $this->present($supplier) + [
            'total_paid' => $this->money($total),
        ];
    }

    /**
     * What we have paid each supplier directly -- payments to them that are
     * not mistaken -- as a `total_paid` column on the rows.
     *
     * @param  Builder<Supplier>  $query
     * @return Builder<Supplier>
     */
    private function withTotalPaid(Builder $query): Builder
    {
        return $query->withSum(['payments as total_paid' => fn ($q) => $q->where('mistaken', false)], 'total');
    }

    private function money(mixed $value): string
    {
        return number_format((float) $value, 2, '.', '');
    }

    /**
     * @return array<string, mixed>
     */
    public function present(Supplier $supplier): array
    {
        return [
            'id' => $supplier->id,
            'name' => $supplier->name,
            'phone' => $supplier->phone,
            'website' => $supplier->website,
            'description' => $supplier->description,
            'notes' => $this->notes->presentMany($supplier),
            'attachments' => $this->attachments->presentMany($supplier),
            'created_by' => $supplier->created_by,
            'creator' => $supplier->relationLoaded('creator') && $supplier->creator
                ? $this->catalog->presentUser($supplier->creator)
                : null,
            'created_at' => $supplier->created_at,
            'updated_at' => $supplier->updated_at,
            'deleted_at' => $supplier->deleted_at,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function tidy(array $data): array
    {
        if (array_key_exists('name', $data) && is_string($data['name'])) {
            $data['name'] = NameKey::tidy($data['name']);
        }

        return $data;
    }
}
