<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ValidatesAttendanceEntry;
use App\Services\TicketIssueService;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Attendance filed outside any one ticket — a technician drives to a store and
 * works issues across several tickets on the same visit. There is no parent
 * ticket to scope against, so the issues only have to exist.
 */
class StoreGlobalAttendanceEntryRequest extends FormRequest
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
            app(TicketIssueService::class)
                ->validateIssuesExist($validator, (array) $this->input('ticket_issue_ids', []));

            $this->validateTechnicianIsOnAnIssue($validator);
        });
    }
}
