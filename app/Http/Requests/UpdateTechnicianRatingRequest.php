<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * A technician's overall rating, and whether they are the one to call first
 * for anything. Only the fields sent change.
 */
class UpdateTechnicianRatingRequest extends FormRequest
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
            'rating' => ['sometimes', 'nullable', 'integer', 'between:1,5'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:2000'],
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
