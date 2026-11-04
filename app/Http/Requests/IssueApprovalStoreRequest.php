<?php

namespace App\Http\Requests;

use App\Enums\ApprovalDecision;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;

/**
 * Record the decision on a quote (-> in_review), with the approval
 * screenshot as files.
 */
class IssueApprovalStoreRequest extends FormRequest
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
            'decision' => ['required', Rule::enum(ApprovalDecision::class)],
            'approver_name' => ['required', 'string', 'max:255'],
            'note' => ['nullable', 'string', 'max:10000'],
            'inspection_id' => ['nullable', 'integer', $this->inspectionOnIssue()],
            'files' => ['nullable', 'array'],
            'files.*' => ['file', 'max:10240'],
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
