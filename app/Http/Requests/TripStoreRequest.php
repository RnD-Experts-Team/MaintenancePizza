<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * `POST trips`: a trip made by hand, with the visits on it.
 */
class TripStoreRequest extends FormRequest
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
            'technician_id' => ['required', 'integer', Rule::exists('technicians', 'id')->whereNull('deleted_at')],
            'planned_date' => ['required', 'date'],
            'instructions' => ['nullable', 'string', 'max:5000'],
            'stops' => ['nullable', 'array'],
            'stops.*.store_id' => ['nullable', 'integer', 'exists:stores,id', 'prohibits:stops.*.other_store'],
            'stops.*.other_store' => ['nullable', 'string', 'max:255'],
            'stops.*.ticket_issue_ids' => ['nullable', 'array'],
            'stops.*.ticket_issue_ids.*' => ['integer', 'distinct', 'exists:ticket_issues,id'],
            'stops.*.purpose' => ['nullable', Rule::in(['work', 'inspection'])],
        ];
    }
}
