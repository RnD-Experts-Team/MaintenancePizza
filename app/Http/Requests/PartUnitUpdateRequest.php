<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * PATCH part-units/{unit}: correct a unit's serial number or warranty date.
 * A serial number is unique within its part.
 */
class PartUnitUpdateRequest extends FormRequest
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
        $unit = $this->route('unit');

        return [
            'serial_number' => [
                'sometimes', 'required', 'string', 'max:255',
                Rule::unique('part_units', 'serial_number')
                    ->where('part_id', $unit?->part_id)
                    ->ignore($unit?->id),
            ],
            'warranty_until' => ['sometimes', 'nullable', 'date'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'serial_number.unique' => 'Another unit of this part already has that serial number.',
        ];
    }
}
