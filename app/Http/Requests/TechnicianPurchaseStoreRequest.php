<?php

namespace App\Http\Requests;

use App\Models\Trip;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * `POST technician-purchases`: the office records a technician's purchase (same body as the link, plus who and which trip).
 */
class TechnicianPurchaseStoreRequest extends FormRequest
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
            ...PublicTripPurchaseRequest::purchaseRules(),
            'technician_id' => ['required', 'integer', Rule::exists('technicians', 'id')->whereNull('deleted_at')],
            'trip_id' => ['nullable', 'integer', 'exists:trips,id'],
            'bought_at' => ['nullable', 'date'],
        ];
    }

    /**
     * @return array<int, \Closure>
     */
    public function after(): array
    {
        return [function (Validator $v) {
            $trip = $this->filled('trip_id') ? Trip::query()->find($this->integer('trip_id')) : null;

            if ($trip !== null && $trip->technician_id !== $this->integer('technician_id')) {
                $v->errors()->add('trip_id', 'That trip belongs to another technician.');
            }
        }];
    }
}
