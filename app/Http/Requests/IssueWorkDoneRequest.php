<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The work is done (scheduled -> work_done): what was done, before/after
 * photos, and optionally the warranty that came with it.
 */
class IssueWorkDoneRequest extends FormRequest
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
            'work_performed' => ['required', 'string', 'max:20000'],
            'files' => ['nullable', 'array'],
            'files.*' => ['file', 'max:10240'],
            'warranty' => ['nullable', 'array'],
            'warranty.body' => ['required_with:warranty', 'nullable', 'string', 'max:10000'],
            'warranty.expiry_date' => ['required_with:warranty.body', 'nullable', 'date'],
        ];
    }
}
