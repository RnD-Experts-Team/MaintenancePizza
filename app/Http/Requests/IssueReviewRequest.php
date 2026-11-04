<?php

namespace App\Http\Requests;

use App\Enums\Priority;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Review an issue (new -> in_review), or update the review while in review.
 * Keys left out are left alone; a key sent as null clears it.
 */
class IssueReviewRequest extends FormRequest
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
            'target_completion_at' => ['sometimes', 'nullable', 'date'],
            'next_action' => ['sometimes', 'nullable', 'string', 'max:255'],
            'assigned_priority' => ['sometimes', 'nullable', Rule::enum(Priority::class)],
        ];
    }
}
