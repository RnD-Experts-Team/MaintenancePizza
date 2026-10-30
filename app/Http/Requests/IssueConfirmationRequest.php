<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The store says whether it works now (by itself, or through the specialist).
 */
class IssueConfirmationRequest extends FormRequest
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
            'working' => ['required', 'boolean'],
            'note' => ['nullable', 'string', 'max:10000'],
            'via' => ['nullable', Rule::in(['store', 'specialist'])],
        ];
    }
}
