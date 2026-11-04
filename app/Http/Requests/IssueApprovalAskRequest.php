<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;

/**
 * Ask for approval of an amount (-> waiting_approval). The level is worked
 * out from the amount and maintenance_settings.approval_levels.
 */
class IssueApprovalAskRequest extends FormRequest
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
            'amount' => ['required', 'numeric', 'min:0', 'max:9999999'],
            'inspection_id' => ['nullable', 'integer', $this->inspectionOnIssue()],
        ];
    }

    /**
     * The inspection named must be one of this issue's.
     */
    protected function inspectionOnIssue(): Exists
    {
        return Rule::exists('issue_inspections', 'id')->where('ticket_issue_id', $this->route('ticketIssue')?->id);
    }
}
