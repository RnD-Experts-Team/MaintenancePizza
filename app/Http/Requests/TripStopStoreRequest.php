<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * `POST trips/{trip}/stops`: one store visit and the ticket issues to do there.
 */
class TripStopStoreRequest extends FormRequest
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
            'store_id' => ['nullable', 'integer', 'exists:stores,id', 'prohibits:other_store'],
            'other_store' => ['nullable', 'string', 'max:255'],
            'ticket_issue_ids' => ['nullable', 'array'],
            'ticket_issue_ids.*' => ['integer', 'distinct', 'exists:ticket_issues,id'],
            'purpose' => ['nullable', Rule::in(['work', 'inspection'])],
        ];
    }
}
