<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Edit a technician in place -- name, phone, trade category, coverage. Before this a
 * technician could not be changed after creation at all; moving one to a
 * different category meant deleting and recreating them.
 */
class UpdateTechnicianRequest extends FormRequest
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
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'phone' => ['sometimes', ...StoreTechnicianRequest::PHONE_RULES],
            'category_id' => ['sometimes', 'nullable', 'integer', 'exists:categories,id'],
            // Where they are based, the stores they can cover, and notes about
            // it. Coverage is the whole list, replaced on every save.
            'location' => ['sometimes', 'nullable', 'string', 'max:255'],
            'coverage_notes' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'coverage_stores' => ['sometimes', 'array', 'max:500'],
            'coverage_stores.*' => ['string', 'distinct', 'exists:stores,store_number'],
        ];
    }
}
