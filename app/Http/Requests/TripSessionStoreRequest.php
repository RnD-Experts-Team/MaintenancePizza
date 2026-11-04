<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * `POST trips/{trip}/sessions`: a session the specialist types in.
 */
class TripSessionStoreRequest extends FormRequest
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
            'clock_in' => ['required', 'date'],
            'clock_out' => ['nullable', 'date', 'after:clock_in'],
            'using_car' => ['nullable', 'boolean'],
            'odometer_in' => ['nullable', 'integer', 'min:0'],
            'odometer_out' => ['nullable', 'integer', 'min:0'],
            'reason' => ['required', 'string', 'max:2000'],
        ];
    }

    /**
     * @return array<int, \Closure>
     */
    public function after(): array
    {
        return [fn (Validator $v) => TripSessionUpdateRequest::checkOdometers($v, $this->input('odometer_in'), $this->input('odometer_out'))];
    }
}
