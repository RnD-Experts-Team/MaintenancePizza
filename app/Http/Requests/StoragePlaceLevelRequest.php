<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * One level of a location's addressing scheme -- "Shelf", "Row".
 *
 * Uniqueness is per LOCATION and among live levels only: two locations may each
 * have a "Shelf" without clashing, and retiring one frees the name.
 */
class StoragePlaceLevelRequest extends FormRequest
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
        $locationId = $this->route('storageLocation')?->id;
        $levelId = $this->route('placeLevel')?->id;

        return [
            'name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('storage_place_levels', 'name')
                    ->where(fn($q) => $q->where('storage_location_id', $locationId))
                    ->ignore($levelId),
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
            'name.unique' => 'This location already has a level with that name.',
        ];
    }
}
