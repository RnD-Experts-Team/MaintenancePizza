<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * How good a technician is at one catalog issue. The whole entry is replaced:
 * anything left out is cleared, and an entry with nothing left is removed.
 */
class UpsertTechnicianAbilityRequest extends FormRequest
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
            'rating' => ['nullable', 'integer', 'between:1,5'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'call_first' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'rating.between' => 'Give between 1 and 5 stars, or none.',
            'rating.integer' => 'Give between 1 and 5 stars, or none.',
        ];
    }
}
