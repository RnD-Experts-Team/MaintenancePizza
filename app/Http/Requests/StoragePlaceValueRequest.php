<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * One declared value on a level -- "C" on Shelf, "8" on Row.
 *
 * Uniqueness is per LEVEL and among live values only, so Shelf C and Row C are
 * both fine and retiring a value frees its name.
 */
class StoragePlaceValueRequest extends FormRequest
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
        $levelId = $this->route('placeLevel')?->id;
        $valueId = $this->route('placeValue')?->id;

        return [
            'value' => [
                'required',
                'string',
                'max:255',
                Rule::unique('storage_place_values', 'value')
                    ->where(fn($q) => $q->where('storage_place_level_id', $levelId))
                    ->ignore($valueId),
            ],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:65535'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'value.unique' => 'That value already exists on this level.',
        ];
    }
}
