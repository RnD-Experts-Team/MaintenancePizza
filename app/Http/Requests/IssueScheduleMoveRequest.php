<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Move a booking to another day and/or hour, with the reason.
 */
class IssueScheduleMoveRequest extends FormRequest
{
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
            'date' => ['required', 'date'],
            'time' => ['nullable', 'date_format:H:i'],
            'reason' => ['required', 'string', 'max:5000'],
        ];
    }
}
