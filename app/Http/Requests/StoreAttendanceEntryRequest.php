<?php

namespace App\Http\Requests;

use App\Services\TicketIssueService;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\DB;

class StoreAttendanceEntryRequest extends FormRequest
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
            'ticket_issue_ids' => ['required', 'array', 'min:1'],
            'ticket_issue_ids.*' => ['integer'],
            'technician_id' => ['required', 'integer', 'exists:technicians,id'],
            'start_clock' => ['nullable', 'date'],
            'end_clock' => ['nullable', 'date'],
            'start_break' => ['nullable', 'date'],
            'end_break' => ['nullable', 'date'],
            'start_parts_run' => ['nullable', 'date'],
            'end_parts_run' => ['nullable', 'date'],
            'files' => ['nullable', 'array'],
            'files.*' => ['file', 'max:10240'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $issueIds = (array) $this->input('ticket_issue_ids', []);

            app(TicketIssueService::class)
                ->validateIssuesBelongToTicket($validator, $this->route('ticket'), $issueIds);

            $technicianId = $this->input('technician_id');
            if (! $technicianId || empty($issueIds)) {
                return;
            }

            // The attending technician must be assigned to at least one target issue.
            $attached = DB::table('technician_ticket_issue')
                ->where('technician_id', $technicianId)
                ->whereIn('ticket_issue_id', $issueIds)
                ->exists();

            if (! $attached) {
                $validator->errors()->add(
                    'technician_id',
                    'The technician must be assigned to at least one of the selected issues first.'
                );
            }
        });
    }
}
