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
            'scheduled_time' => ['nullable', 'date_format:H:i'],
            'instructions' => ['nullable', 'string', 'max:5000'],
        ];
    }
}
