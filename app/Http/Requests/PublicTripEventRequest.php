<?php

namespace App\Http\Requests;

use App\Enums\TripEventKind;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Technician link: a break / drive / parts run starting or ending now.
 */
class PublicTripEventRequest extends FormRequest
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
            'kind' => ['required', Rule::in(TripEventKind::values())],
        ];
    }
}
