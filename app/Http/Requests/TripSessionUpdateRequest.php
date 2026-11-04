<?php

namespace App\Http\Requests;

use App\Models\TripSession;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * `PATCH trips/{trip}/sessions/{session}`: correct a session. Only what is sent changes; `clock_out: null` opens it again.
 */
class TripSessionUpdateRequest extends FormRequest
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
            'clock_in' => ['sometimes', 'required', 'date'],
            'clock_out' => ['sometimes', 'nullable', 'date'],
            'using_car' => ['sometimes', 'boolean'],
            'odometer_in' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'odometer_out' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'reason' => ['required', 'string', 'max:2000'],
        ];
    }

    /**
     * @return array<int, \Closure>
     */
    public function after(): array
    {
        /** @var TripSession|null $session */
        $session = $this->route('session');

        return [fn (Validator $v) => self::checkOdometers(
            $v,
            $this->exists('odometer_in') ? $this->input('odometer_in') : $session?->odometer_in,
            $this->exists('odometer_out') ? $this->input('odometer_out') : $session?->odometer_out,
        )];
    }

    public static function checkOdometers(Validator $v, mixed $in, mixed $out): void
    {
        if (is_numeric($in) && is_numeric($out) && (int) $out < (int) $in) {
            $v->errors()->add('odometer_out', 'The odometer at clock-out cannot be less than at clock-in.');
        }
    }
}
