<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * POST stock-counts: start a count sheet for a storage location, or for one
 * place in it.
 */
class StockCountCreateRequest extends FormRequest
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
            'storage_location_id' => ['required', 'integer', 'exists:storage_locations,id'],
            'storage_place_value_id' => ['nullable', 'integer', 'exists:storage_place_values,id'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ];
    }
}
