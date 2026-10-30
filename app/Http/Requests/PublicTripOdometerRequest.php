<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Technician link: clock out / arrive at a store, with the odometer and its photo.
 */
class PublicTripOdometerRequest extends FormRequest
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
            'odometer' => ['nullable', 'integer', 'min:0'],
            'photo' => ['nullable', 'file', 'max:10240', 'mimetypes:image/*'],
        ];
    }
}
