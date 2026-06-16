<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Generic note creation for any entity. One note (plus optional files) per call;
 * post repeatedly to add as many as needed. `type` is free-form here — only the
 * ticket final-note endpoint constrains it to App\Enums\FinalNoteType.
 */
class StoreNoteRequest extends FormRequest
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
            'type' => ['nullable', 'string', 'max:255'],
            'files' => ['nullable', 'array'],
            'files.*' => ['file', 'max:10240'],
        ];
    }
}
