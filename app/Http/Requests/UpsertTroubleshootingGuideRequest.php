<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * PUT /api/issues/{issue}/troubleshooting -- the whole guide, replaced.
 */
class UpsertTroubleshootingGuideRequest extends FormRequest
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
            // In order: what to try first, then next. Plain text. Blank rows
            // (an editor's empty line) are dropped, not refused.
            'steps' => ['required', 'array', 'min:1', 'max:30'],
            'steps.*' => ['nullable', 'string', 'max:1000'],
            // A video, a PDF manual, a vendor page.
            'link_url' => ['nullable', 'url:http,https', 'max:2048'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v) {
            $real = array_filter((array) $this->input('steps', []), fn ($s) => trim((string) $s) !== '');
            if ($real === [] && !$v->errors()->has('steps')) {
                $v->errors()->add('steps', 'Add at least one step to try.');
            }
        });
    }
}
