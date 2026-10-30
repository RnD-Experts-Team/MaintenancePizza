<?php

namespace App\Http\Requests;

use App\Rules\UniqueName;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreTechnicianRequest extends FormRequest
{
    /**
     * A phone number as people type it: digits with the usual punctuation
     * ("+1 (234) 567-8900", "234.567.8900"), plus an optional extension
     * ("x12", "ext. 12"). Not normalised -- it is shown and dialled as typed.
     */
    public const PHONE_RULES = [
        'nullable',
        'string',
        'max:32',
        // At least three digits overall, then the shape described above.
        'regex:/^(?=(?:\D*\d){3})\+?[0-9(][0-9 ().\-]*(\s*(x|ext\.?)\s*[0-9]{1,6})?$/i',
    ];

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255', new UniqueName('technicians', 'technician')],
            'phone' => self::PHONE_RULES,
            'contact_person' => ['nullable', 'string', 'max:255'],
            'hourly_rate' => ['nullable', 'numeric', 'min:0', 'max:100000'],
            'emergency_available' => ['boolean'],
            'emergency_notes' => ['nullable', 'string', 'max:2000'],
            'category_id' => ['nullable', 'integer', 'exists:categories,id'],
            // Where they are based, the stores they can cover, and notes about
            // it. Coverage is the whole list, replaced on every save.
            'location' => ['nullable', 'string', 'max:255'],
            'coverage_notes' => ['nullable', 'string', 'max:2000'],
            'coverage_stores' => ['array', 'max:500'],
            'coverage_stores.*' => ['string', 'distinct', 'exists:stores,store_number'],
        ];
    }
}
