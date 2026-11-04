<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * `PATCH trips/{trip}`: the day, the instructions and how long the link may sit idle.
 */
class TripUpdateRequest extends FormRequest
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
            'planned_date' => ['sometimes', 'required', 'date'],
            'instructions' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'link_idle_hours' => ['sometimes', 'required', 'integer', 'min:1', 'max:8760'],
            'reason' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
