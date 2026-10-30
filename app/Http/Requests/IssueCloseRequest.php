<?php

namespace App\Http\Requests;

use App\Enums\Resolution;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Close the issue with how it ended. The close checklist must be complete,
 * or override_reason given.
 */
class IssueCloseRequest extends FormRequest
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
            'resolution' => ['required', Rule::in(array_map(fn (Resolution $r) => $r->value, Resolution::closable()))],
            'final_note' => ['nullable', 'string', 'max:20000'],
            'what_we_learned' => ['nullable', 'string', 'max:20000'],
            'override_reason' => ['nullable', 'string', 'max:5000'],
        ];
    }
}
