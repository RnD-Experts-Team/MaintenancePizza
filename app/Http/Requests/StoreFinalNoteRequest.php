<?php

namespace App\Http\Requests;

use App\Enums\FinalNoteType;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Append a typed closing note to a ticket. A ticket may have many; each is one
 * of the FinalNoteType kinds and may carry its own files.
 */
class StoreFinalNoteRequest extends FormRequest
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
            'body' => ['required', 'string'],
            'type' => ['required', Rule::enum(FinalNoteType::class)],
            'files' => ['nullable', 'array'],
            'files.*' => ['file', 'max:10240'],
        ];
    }
}
