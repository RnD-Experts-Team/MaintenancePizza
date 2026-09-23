<?php

namespace App\Http\Requests\Concerns;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Support\Facades\DB;

/**
 * The rules an attendance entry obeys wherever it is filed from — the
 * ticket-nested endpoint and the cross-ticket one. Kept in one place so the
 * two cannot drift apart; only the ticket-scoping rule differs between them.
 */
trait ValidatesAttendanceEntry
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    protected function attendanceRules(): array
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
            'start_travel' => ['nullable', 'date'],
            'end_travel' => ['nullable', 'date'],
            // Notes filed with the entry itself, so a travel leg can say
            // "store A -> store B" and a parts run "back to storage" without a
            // second round trip. Same shape the daily pay lines already use.
            'notes' => ['nullable', 'array'],
            'notes.*.body' => ['required_with:notes.*', 'string'],
            'notes.*.type' => ['nullable', 'string'],
            'notes.*.files' => ['nullable', 'array'],
            'notes.*.files.*' => ['file', 'max:10240'],
            'files' => ['nullable', 'array'],
            'files.*' => ['file', 'max:10240'],
        ];
    }

    /**
     * The attending technician must already be on at least one target issue.
     */
    protected function validateTechnicianIsOnAnIssue(Validator $validator): void
    {
        $issueIds = (array) $this->input('ticket_issue_ids', []);
        $technicianId = $this->input('technician_id');

        if (! $technicianId || empty($issueIds)) {
            return;
        }

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
    }
}
