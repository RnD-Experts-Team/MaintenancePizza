<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Book an inspection with a technician (-> inspection, on his trip for that day).
 */
class IssueInspectionStoreRequest extends FormRequest
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
            'technician_id' => ['required', 'integer', Rule::exists('technicians', 'id')->whereNull('deleted_at')],
            'scheduled_date' => ['required', 'date'],
            'scheduled_time' => ['nullable', 'date_format:H:i', 'required_with:scheduled_time_to'],
            'scheduled_time_to' => ['nullable', 'date_format:H:i', 'after:scheduled_time'],
            // What the inspection itself costs, when it is known up front. A payment's
            // inspection line for it takes over; until then this is its cost.
            'inspection_fee' => ['nullable', 'numeric', 'min:0', 'max:9999999999'],
            'instructions' => ['nullable', 'string', 'max:5000'],
        ];
    }
}
