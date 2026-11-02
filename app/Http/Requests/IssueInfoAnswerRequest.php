<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The store's answer to a question -- typed by the store, or by the
 * specialist after a phone call (`via`, default store).
 */
class IssueInfoAnswerRequest extends FormRequest
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
            'answer' => ['required', 'string', 'max:10000'],
            // Optional: who gave the answer, as typed ("Sara, by phone").
            'answered_by_name' => ['nullable', 'string', 'max:120'],
            'files' => ['nullable', 'array'],
            'files.*' => ['file', 'max:10240'],
        ];
    }
}
