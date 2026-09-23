<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ValidatesAttendanceEntry;
use App\Services\TicketIssueService;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

class StoreAttendanceEntryRequest extends FormRequest
{
    use ValidatesAttendanceEntry;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return $this->attendanceRules();
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $issueIds = (array) $this->input('ticket_issue_ids', []);
            $issues = app(TicketIssueService::class);

            // One visit can cover issues on several tickets, so the rule is
            // "touches this ticket", not "belongs entirely to it". Every id
            // must still be a real issue.
            $issues->validateAtLeastOneIssueBelongsToTicket($validator, $this->route('ticket'), $issueIds);
            $issues->validateIssuesExist($validator, $issueIds);

            $this->validateTechnicianIsOnAnIssue($validator);
        });
    }
}
