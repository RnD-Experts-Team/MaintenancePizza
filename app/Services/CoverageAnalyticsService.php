<?php

namespace App\Services;

use App\Models\Store;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * GET /api/maintenance-analytics/coverage (docs/MAINTENANCE-V2.md §6):
 * for every store × issue, how many technicians we could call.
 *
 * A technician covers a CELL when he covers the store (store_technician) and
 * the issue (technician_issue_abilities). Deleted technicians cover nothing.
 * A cell with one technician or none is a GAP: nobody to call when he is
 * busy.
 *
 * Defaults: every store that has ever had a ticket, every catalog issue that
 * is not deleted. The counts are one grouped query; PHP only lays the
 * answer out over the grid, filling the cells nobody covers.
 */
class CoverageAnalyticsService
{
    /**
     * @param  Collection<int, Store>|null  $stores  null = every store with tickets
     * @param  array<int, int>|null  $issueIds  null = every catalog issue
     * @return array<string, mixed>
     */
    public function report(?Collection $stores, ?array $issueIds, ?int $categoryId): array
    {
        $stores ??= Store::query()
            ->whereIn('id', DB::table('tickets')->whereNotNull('store_id')->select('store_id'))
            ->orderBy('store_number')
            ->get();

        $issues = DB::table('issues')
            ->when($issueIds !== null, fn (Builder $q) => $q->whereIn('id', $issueIds), fn (Builder $q) => $q->whereNull('deleted_at'))
            ->orderBy('title')->orderBy('id')
            ->get(['id', 'title']);

        $storeIds = $stores->pluck('id')->map(fn ($id) => (int) $id)->all();
        $ids = $issues->pluck('id')->map(fn ($id) => (int) $id)->all();

        $technicians = fn () => DB::table('technicians as t')
            ->whereNull('t.deleted_at')
            ->when($categoryId !== null, fn (Builder $q) => $q->where('t.category_id', $categoryId));

        $forStores = fn (Builder $q) => $q->whereExists(fn (Builder $s) => $s->selectRaw('1')
            ->from('store_technician as st')
            ->whereColumn('st.technician_id', 't.id')
            ->whereIn('st.store_id', $storeIds));
        $forIssues = fn (Builder $q) => $q->whereExists(fn (Builder $s) => $s->selectRaw('1')
            ->from('technician_issue_abilities as a')
            ->whereColumn('a.technician_id', 't.id')
            ->whereIn('a.issue_id', $ids));

        $cells = DB::table('store_technician as st')
            ->join('technician_issue_abilities as a', 'a.technician_id', '=', 'st.technician_id')
            ->join('technicians as t', 't.id', '=', 'st.technician_id')
            ->whereNull('t.deleted_at')
            ->when($categoryId !== null, fn (Builder $q) => $q->where('t.category_id', $categoryId))
            ->whereIn('st.store_id', $storeIds)
            ->whereIn('a.issue_id', $ids)
            ->groupBy('st.store_id', 'a.issue_id')
            ->selectRaw('st.store_id, a.issue_id, COUNT(DISTINCT t.id) as technicians, '
                . 'SUM(CASE WHEN t.emergency_available = 1 THEN 1 ELSE 0 END) as emergency, '
                . 'AVG(a.rating) as average_rating')
            ->get()
            ->keyBy(fn ($r) => $r->store_id . ':' . $r->issue_id);

        $grid = [];
        $gaps = 0;
        foreach ($stores as $store) {
            foreach ($issues as $issue) {
                $row = $cells->get($store->id . ':' . $issue->id);
                $count = (int) ($row->technicians ?? 0);
                $gap = $count <= 1;
                $gaps += $gap ? 1 : 0;

                $grid[] = [
                    'store_number' => $store->store_number,
                    'issue_id' => (int) $issue->id,
                    'title' => $issue->title,
                    'technicians' => $count,
                    'emergency' => (int) ($row->emergency ?? 0),
                    'average_rating' => $row?->average_rating === null ? null : number_format((float) $row->average_rating, 1, '.', ''),
                    'gap' => $gap,
                ];
            }
        }

        return [
            'kpis' => [
                'technicians_for_stores' => $forStores($technicians())->count(),
                'technicians_for_issues' => $forIssues($technicians())->count(),
                'technicians_for_both' => $forIssues($forStores($technicians()))->count(),
                // Of those who could take a call in this grid, who can come at night.
                'emergency_available' => $forIssues($forStores($technicians()))->where('t.emergency_available', true)->count(),
            ],
            'cells' => $grid,
            'gaps' => $gaps,
        ];
    }
}
