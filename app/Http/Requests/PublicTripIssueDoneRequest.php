<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Technician link: a job done (work: what he did; inspection: what he found and what it would cost), with before/after photos.
 */
class PublicTripIssueDoneRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'note' => ['nullable', 'string', 'max:10000'],
            'findings' => ['nullable', 'string', 'max:10000'],
            'estimated_cost' => ['nullable', 'numeric', 'min:0', 'max:9999999999'],
            'files' => ['nullable', 'array'],
            'files.*' => ['file', 'max:10240'],
            // Photos of the job before and after; stored on the issue named
            // "Before - …" / "After - …" (AttachmentService::BEFORE_PREFIX).
            'before' => ['nullable', 'array'],
            'before.*' => ['file', 'max:10240'],
            'after' => ['nullable', 'array'],
            'after.*' => ['file', 'max:10240'],
        ];
    }
}
