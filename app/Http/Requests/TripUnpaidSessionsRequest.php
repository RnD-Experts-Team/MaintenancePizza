<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * `GET trip-sessions/unpaid` filters.
 */
class TripUnpaidSessionsRequest extends FormRequest
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
            'technician_id' => ['nullable', 'integer'],
        ];
    }
}
