<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;

/**
 * GET /api/maintenance-reports?start_date=2026-11-09&end_date=2026-11-15
 *
 * Two DATES, both included. An issue's date is the day it was submitted, in
 * the app's timezone; a payment's is the day it was paid.
 */
class MaintenanceReportsRequest extends FormRequest
{
    /** The longest range one call may ask for. */
    public const MAX_DAYS = 400;

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
            'start_date' => ['required', 'date_format:Y-m-d'],
            'end_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:start_date'],
        ];
    }

    public function after(): array
    {
        return [
            function ($validator) {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }
                if ($this->startDate()->diffInDays($this->endDate()) + 1 > self::MAX_DAYS) {
                    $validator->errors()->add('end_date', 'The range can be at most '.self::MAX_DAYS.' days.');
                }
            },
        ];
    }

    public function startDate(): Carbon
    {
        return Carbon::createFromFormat('Y-m-d', (string) $this->input('start_date'))->startOfDay();
    }

    public function endDate(): Carbon
    {
        return Carbon::createFromFormat('Y-m-d', (string) $this->input('end_date'))->startOfDay();
    }
}
