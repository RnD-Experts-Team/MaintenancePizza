<?php

namespace App\Http\Requests;

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
            'name' => ['required', 'string', 'max:255'],
            'phone' => self::PHONE_RULES,
            'category_id' => ['nullable', 'integer', 'exists:categories,id'],
        ];
    }
}
