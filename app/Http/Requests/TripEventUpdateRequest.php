<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * `PATCH .../events/{event}`: move an event in time.
 */
class TripEventUpdateRequest extends FormRequest
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
            'at' => ['required', 'date'],
            'reason' => ['required', 'string', 'max:2000'],
        ];
    }
}
