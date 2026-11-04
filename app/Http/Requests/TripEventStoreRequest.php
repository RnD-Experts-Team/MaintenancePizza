<?php

namespace App\Http\Requests;

use App\Enums\TripEventKind;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * `POST trips/{trip}/sessions/{session}/events`: a break / drive / parts run typed in.
 */
class TripEventStoreRequest extends FormRequest
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
            'at' => ['required', 'date'],
            'reason' => ['required', 'string', 'max:2000'],
        ];
    }
}
