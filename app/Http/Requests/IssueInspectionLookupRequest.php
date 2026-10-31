<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * `GET issue-inspections` (docs/MAINTENANCE.md §4 "Inspection lookup"):
 * the filters of the payment dialog's Inspection line.
 */
class IssueInspectionLookupRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'technician_id' => ['nullable', 'integer'],
            'unpaid' => ['nullable', 'boolean'],
            'search' => ['nullable', 'string', 'max:255'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }
}
