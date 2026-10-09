<?php

namespace App\Http\Requests;

use App\Services\MaintenanceAnalyticsService;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Validator;

/**
 * GET /api/maintenance-analytics/{section}
 *
 *   stores[]=03795-00001&stores[]=...   required, the stores to report on
 *   from=2026-10-05T04:00:00Z           required, an INSTANT (start of the
 *   to=2026-10-06T04:00:00Z                       viewer's "yesterday", etc.)
 *
 * The range is two instants, never two dates: "yesterday" is the viewer's
 * yesterday, so the browser turns its own local midnights into UTC instants
 * and the server compares timestamps -- no whereDate(), which would cut the
 * day at UTC midnight instead.
 */
class MaintenanceAnalyticsRequest extends FormRequest
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
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v) {
            if ($v->errors()->isNotEmpty()) {
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
}
