<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Technician link: clock in, with the car and odometer (and a photo of it) when driving.
 */
class PublicTripClockInRequest extends FormRequest
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
            'using_car' => ['nullable', 'boolean'],
            'odometer' => ['nullable', 'integer', 'min:0'],
            'photo' => ['nullable', 'file', 'max:10240', 'mimetypes:image/*'],
        ];
    }
}
