<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * PATCH stock-counts/{stockCount}: what was counted, per part (null = not
 * counted). A part not on the sheet yet is added.
 */
class StockCountUpdateRequest extends FormRequest
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
            'lines' => ['sometimes', 'array'],
            'lines.*.part_id' => ['required', 'integer', 'distinct', 'exists:parts,id'],
            'lines.*.counted' => ['present', 'nullable', 'numeric', 'min:0', 'max:9999999999'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:5000'],
        ];
    }
}
