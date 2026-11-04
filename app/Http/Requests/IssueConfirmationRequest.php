<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

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
            // Optional: who said so, as typed ("Sara, by phone").
            'confirmed_by_name' => ['nullable', 'string', 'max:120'],
        ];
    }
}
