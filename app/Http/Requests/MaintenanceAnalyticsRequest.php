<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;

/**
 * GET /api/maintenance-analytics/{section}
 *
 * summary | activity | watchlist
 *   stores[]=03795-00001&stores[]=...   required, the stores to report on
 *   from=2026-10-05T04:00:00Z           required, an INSTANT (start of the
 *   to=2026-10-06T04:00:00Z                       viewer's "yesterday", etc.)
 *
 *   The range is two instants, never two dates: "yesterday" is the viewer's
 *   yesterday, so the browser turns its own local midnights into UTC instants
 *   and the server compares timestamps -- no whereDate(), which would cut the
 *   day at UTC midnight instead.
 *
 * finances (docs/MAINTENANCE.md §6)
 *   from=2026-10-01&to=2026-10-31       required DATES, both included (a
 *                                       payment is dated, not timed), any
 *                                       range
 *   stores[], category_id, technician_id, supplier_id   optional
 *
 * coverage
 *   stores[], issue_ids[], category_id  all optional
 */
class MaintenanceAnalyticsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * The technicians to narrow to: technician_ids[], else technician_id, else null (all).
     *
     * @return array<int, int>|null
     */
    public function technicianIds(): ?array
    {
        $ids = array_values(array_unique(array_map('intval', (array) $this->input('technician_ids', []))));
        if ($ids === [] && $this->filled('technician_id')) {
            $ids = [$this->integer('technician_id')];
        }

        return $ids === [] ? null : $ids;
    }

    private function section(): string
    {
        return (string) $this->route('section');
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return match ($this->section()) {
            'finances' => [
                'stores' => ['nullable', 'array'],
                'stores.*' => ['string', 'distinct', 'exists:stores,store_number'],
                'from' => ['required', 'date'],
                'to' => ['required', 'date', 'after_or_equal:from'],
                'category_id' => ['nullable', 'integer', 'exists:categories,id'],
                // One technician (older callers) or several, checked off together.
                'technician_id' => ['nullable', 'integer', 'exists:technicians,id'],
                'technician_ids' => ['nullable', 'array'],
                'technician_ids.*' => ['integer', 'distinct', 'exists:technicians,id'],
                'supplier_id' => ['nullable', 'integer', 'exists:suppliers,id'],
            ],
            'coverage' => [
                'stores' => ['nullable', 'array'],
                'stores.*' => ['string', 'distinct', 'exists:stores,store_number'],
                'issue_ids' => ['nullable', 'array'],
                'issue_ids.*' => ['integer', 'distinct', 'exists:issues,id'],
                'category_id' => ['nullable', 'integer', 'exists:categories,id'],
            ],
            default => [
                // Required and non-empty: pizzasys checks the caller against every
                // store named here, so a report always has a store context.
                'stores' => ['required', 'array', 'min:1'],
                'stores.*' => ['string', 'distinct', 'exists:stores,store_number'],
                'from' => ['required', 'date'],
                'to' => ['required', 'date', 'after:from'],
                'recurring_min' => ['nullable', 'integer', 'min:2'],
                'recurring_days' => ['nullable', 'integer', 'min:7'],
                'untouched_days' => ['nullable', 'integer', 'min:1'],
                'page' => ['nullable', 'integer', 'min:1'],
                'per_page' => ['nullable', 'integer', 'min:1'],
            ],
        };
    }

    public function from(): Carbon
    {
        return Carbon::parse((string) $this->input('from'))->utc();
    }

    public function to(): Carbon
    {
        return Carbon::parse((string) $this->input('to'))->utc();
    }

    /** finances: the first day of the range (the date as written, no zone shift). */
    public function fromDate(): Carbon
    {
        return Carbon::parse(substr((string) $this->input('from'), 0, 10))->startOfDay();
    }

    /** finances: the last day of the range, included. */
    public function toDate(): Carbon
    {
        return Carbon::parse(substr((string) $this->input('to'), 0, 10))->startOfDay();
    }

    /** @return array<int, int> */
    public function ids(string $key): array
    {
        return array_values(array_map('intval', (array) $this->input($key, [])));
    }
}
