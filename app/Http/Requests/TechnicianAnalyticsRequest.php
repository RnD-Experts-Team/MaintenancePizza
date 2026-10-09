<?php

namespace App\Http\Requests;

use App\Services\TechnicianAnalyticsService;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Validator;

/**
 * GET /api/technician-analytics
 * GET /api/technicians/{technician}/analytics
 *
 *   from=2026-10-01T04:00:00Z  to=2026-11-01T04:00:00Z   required, INSTANTS --
 *       the viewer's local midnights; work (visits) is compared on these.
 *   date_from=2026-10-01  date_to=2026-10-31             required, local DAYS,
 *       inclusive -- pay is compared on these, a pay sheet's date being a day.
 *   stores[]=03795-00001  issue_ids[]=3  category_ids[]=2  technician_ids[]=7
 *       optional filters; none means all.
 */
class TechnicianAnalyticsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'from' => ['required', 'date'],
            'to' => ['required', 'date', 'after:from'],
            'date_from' => ['required', 'date_format:Y-m-d'],
            'date_to' => ['required', 'date_format:Y-m-d', 'after_or_equal:date_from'],
            'stores' => ['nullable', 'array', 'max:500'],
            'stores.*' => ['string', 'distinct', 'exists:stores,store_number'],
            'issue_ids' => ['nullable', 'array', 'max:200'],
            'issue_ids.*' => ['integer', 'distinct'],
            'category_ids' => ['nullable', 'array', 'max:100'],
            'category_ids.*' => ['integer', 'distinct'],
            'technician_ids' => ['nullable', 'array', 'max:500'],
            'technician_ids.*' => ['integer', 'distinct'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v) {
            if ($v->errors()->isNotEmpty()) {
                return;
            }

            $max = TechnicianAnalyticsService::MAX_RANGE_DAYS;
            if (Carbon::parse($this->input('from'))->diffInDays(Carbon::parse($this->input('to'))) > $max) {
                $v->errors()->add('to', "The range can be at most {$max} days.");
            }
        });
    }

    /**
     * The filters, ready for TechnicianAnalyticsService.
     *
     * @return array{from: Carbon, to: Carbon, date_from: string, date_to: string, store_ids: array<int, int>, issue_ids: array<int, int>, category_ids: array<int, int>, technician_ids: array<int, int>}
     */
    public function filters(TechnicianAnalyticsService $service): array
    {
        $ints = fn (string $key) => array_values(array_map('intval', (array) $this->input($key, [])));

        return [
            'from' => Carbon::parse((string) $this->input('from'))->utc(),
            'to' => Carbon::parse((string) $this->input('to'))->utc(),
            'date_from' => (string) $this->input('date_from'),
            'date_to' => (string) $this->input('date_to'),
            'store_ids' => $service->storeIds(array_values((array) $this->input('stores', []))),
            'issue_ids' => $ints('issue_ids'),
            'category_ids' => $ints('category_ids'),
            'technician_ids' => $ints('technician_ids'),
        ];
    }
}
