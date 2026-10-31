<?php

namespace App\Http\Requests;

use App\Enums\IssueStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * `GET ticket-issues` (docs/MAINTENANCE.md §2 "Issue lookup"): the filters
 * of the issue pickers.
 */
class TicketIssueLookupRequest extends FormRequest
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
            'search' => ['nullable', 'string', 'max:255'],
            'store' => ['nullable', 'string', 'max:255'],
            'statuses' => ['nullable', 'array'],
            'statuses.*' => ['string', Rule::in(IssueStatus::values())],
            'limit' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }
}
