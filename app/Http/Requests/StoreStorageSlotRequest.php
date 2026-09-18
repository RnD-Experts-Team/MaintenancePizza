<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A named place inside one storage location.
 *
 * Uniqueness is per LOCATION and among live slots only -- two locations may
 * each have a "Shelf A" without clashing, and retiring one frees the name.
 */
class StoreStorageSlotRequest extends FormRequest
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
        $slotId = $this->route('storageSlot')?->id;

        return [
            'name' => [
                'required', 'string', 'max:255',
                Rule::unique('storage_slots', 'name')
                    ->where(fn ($q) => $q->where('storage_location_id', $locationId))
                    ->whereNull('deleted_at')
                    ->ignore($slotId),
            ],
            'code' => ['nullable', 'string', 'max:64'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:65535'],
        ];
    }
}
