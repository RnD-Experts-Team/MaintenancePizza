<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * What the inspection found (marks it done; the issue's status stays).
 */
class IssueInspectionResultRequest extends FormRequest
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
            'findings' => ['required', 'string', 'max:10000'],
            'recommended_repair' => ['nullable', 'string', 'max:10000'],
            'parts_needed' => ['nullable', 'string', 'max:10000'],
            'estimated_cost' => ['nullable', 'numeric', 'min:0', 'max:9999999'],
            'files' => ['nullable', 'array'],
            'files.*' => ['file', 'max:10240'],
        ];
    }
}
