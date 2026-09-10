<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Validator;

class StoreDailyPayEntryRequest extends FormRequest
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
            'date' => ['required', 'date'],
            // Optional optimistic-concurrency guard on edit: send the
            // updated_at you last read and the edit is refused with 409 if
            // somebody else has changed the entry since.
            'expected_updated_at' => ['nullable', 'date'],

            // One payment per payee. A payee may be a company, in which case it
            // is a technician row named after the company.
            'payments' => ['required', 'array', 'min:1'],
            'payments.*.technician_id' => ['required', 'integer', 'exists:technicians,id', 'distinct'],

            // Money the payment carries in its own right — the part that is not
            // attributable to one store. The lines carry the part that is.
            'payments.*.hourly_payment_rate' => ['nullable', 'numeric', 'min:0'],
            'payments.*.lump_sum' => ['nullable', 'numeric', 'min:0'],
            'payments.*.gas' => ['nullable', 'numeric', 'min:0'],
            'payments.*.money_owed' => ['nullable', 'numeric', 'min:0'],

            'payments.*.notes' => ['nullable', 'array'],
            'payments.*.notes.*.body' => ['required_with:payments.*.notes.*', 'string'],
            'payments.*.notes.*.type' => ['nullable', 'string'],
            'payments.*.notes.*.files' => ['nullable', 'array'],
            'payments.*.notes.*.files.*' => ['file', 'max:10240'],
            'payments.*.files' => ['nullable', 'array'],
            'payments.*.files.*' => ['file', 'max:10240'],

            // One line per store the payment covers.
            'payments.*.lines' => ['required', 'array', 'min:1'],
            'payments.*.lines.*.store_id' => ['nullable', 'integer', 'exists:stores,id'],
            // Mirrors tickets: a line may name an off-system location instead.
            'payments.*.lines.*.other_store' => ['nullable', 'string', 'max:255'],
            // Leave the hours out to have them gathered from the attendance
            // records; supply them to override, and recalculate will then leave
            // this line alone.
            'payments.*.lines.*.total_working_hours' => ['nullable', 'numeric', 'min:0'],
            'payments.*.lines.*.travel_time' => ['nullable', 'numeric', 'min:0'],
            'payments.*.lines.*.total_break_time' => ['nullable', 'numeric', 'min:0'],
            'payments.*.lines.*.parts_run_time' => ['nullable', 'numeric', 'min:0'],
            'payments.*.lines.*.gas' => ['nullable', 'numeric', 'min:0'],
            'payments.*.lines.*.lump_sum' => ['nullable', 'numeric', 'min:0'],
            'payments.*.lines.*.hourly_payment_rate' => ['nullable', 'numeric', 'min:0'],
            'payments.*.lines.*.money_owed' => ['nullable', 'numeric', 'min:0'],
            'payments.*.lines.*.ticket_issue_ids' => ['nullable', 'array'],
            'payments.*.lines.*.ticket_issue_ids.*' => ['integer', 'exists:ticket_issues,id'],
            'payments.*.lines.*.notes' => ['nullable', 'array'],
            'payments.*.lines.*.notes.*.body' => ['required_with:payments.*.lines.*.notes.*', 'string'],
            'payments.*.lines.*.notes.*.type' => ['nullable', 'string'],
            'payments.*.lines.*.notes.*.files' => ['nullable', 'array'],
            'payments.*.lines.*.notes.*.files.*' => ['file', 'max:10240'],
            'payments.*.lines.*.files' => ['nullable', 'array'],
            'payments.*.lines.*.files.*' => ['file', 'max:10240'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'payments.*.technician_id.distinct' => 'Each payee may only appear once on a pay sheet. Put all their stores on the one payment.',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v) {
            foreach ((array) $this->input('payments', []) as $p => $payment) {
                $technicianId = $payment['technician_id'] ?? null;

                foreach ($payment['lines'] ?? [] as $l => $line) {
                    if (empty($line['store_id']) && empty($line['other_store'])) {
                        $v->errors()->add(
                            "payments.{$p}.lines.{$l}.store_id",
                            'Give the line a store, or an other_store name for an off-system location.'
                        );
                    }

                    $issueIds = $line['ticket_issue_ids'] ?? [];

                    // The payee has to be on the issues before their pay can
                    // cover them. Nothing to check if either half is missing.
                    if (! $technicianId || empty($issueIds)) {
                        continue;
                    }

                    $invalid = collect($issueIds)->filter(fn ($issueId) =>
                        ! DB::table('technician_ticket_issue')
                            ->where('technician_id', $technicianId)
                            ->where('ticket_issue_id', $issueId)
                            ->exists()
                    )->values()->all();

                    if (! empty($invalid)) {
                        $v->errors()->add(
                            "payments.{$p}.lines.{$l}.ticket_issue_ids",
                            'Technician is not assigned to issue(s): ' . implode(', ', $invalid) . '.'
                        );
                    }
                }
            }
        });
    }
}
