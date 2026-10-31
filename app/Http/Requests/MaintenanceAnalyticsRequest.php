<?php

namespace App\Http\Requests;

use App\Services\FinanceAnalyticsService;
use App\Services\MaintenanceAnalyticsService;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Validator;

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
 *                                       payment is dated, not timed), at
 *                                       most 400 days
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
                'stores' => ['nullable', 'array', 'max:500'],
                'stores.*' => ['string', 'distinct', 'exists:stores,store_number'],
                'from' => ['required', 'date'],
                'to' => ['required', 'date', 'after_or_equal:from'],
                'category_id' => ['nullable', 'integer', 'exists:categories,id'],
                'technician_id' => ['nullable', 'integer', 'exists:technicians,id'],
                'supplier_id' => ['nullable', 'integer', 'exists:suppliers,id'],
            ],
            'coverage' => [
                'stores' => ['nullable', 'array', 'max:500'],
                'stores.*' => ['string', 'distinct', 'exists:stores,store_number'],
                'issue_ids' => ['nullable', 'array', 'max:1000'],
                'issue_ids.*' => ['integer', 'distinct', 'exists:issues,id'],
                'category_id' => ['nullable', 'integer', 'exists:categories,id'],
            ],
            default => [
                // Required and non-empty: pizzasys checks the caller against every
                // store named here, so a report always has a store context.
                'stores' => ['required', 'array', 'min:1', 'max:500'],
                'stores.*' => ['string', 'distinct', 'exists:stores,store_number'],
                'from' => ['required', 'date'],
                'to' => ['required', 'date', 'after:from'],
                'recurring_min' => ['nullable', 'integer', 'min:2', 'max:20'],
                'recurring_days' => ['nullable', 'integer', 'min:7', 'max:365'],
                'untouched_days' => ['nullable', 'integer', 'min:1', 'max:90'],
                'page' => ['nullable', 'integer', 'min:1'],
                'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            ],
        };
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v) {
            if ($v->errors()->isNotEmpty() || $this->section() === 'coverage') {
                return;
            }

            if ($this->section() === 'finances') {
                $max = FinanceAnalyticsService::MAX_RANGE_DAYS;
                // Both dates are included, so Oct 1 → Oct 1 is one day.
                if ($this->fromDate()->diffInDays($this->toDate()) + 1 > $max) {
                    $v->errors()->add('to', "The range can be at most {$max} days.");
                }

                return;
            }

            $max = MaintenanceAnalyticsService::MAX_RANGE_DAYS;
            if (Carbon::parse($this->input('from'))->diffInDays(Carbon::parse($this->input('to'))) > $max) {
                $v->errors()->add('to', "The range can be at most {$max} days.");
            }
        });
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
