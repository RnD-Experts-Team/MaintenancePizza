<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Schedule the work with a technician (-> scheduled, on his trip for that day).
 */
class IssueScheduleStoreRequest extends FormRequest
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
            'date' => ['required', 'date'],
            'time' => ['nullable', 'date_format:H:i'],
            'expected_completion_date' => ['nullable', 'date', 'after_or_equal:date'],
            'instructions' => ['nullable', 'string', 'max:5000'],
        ];
    }
}
