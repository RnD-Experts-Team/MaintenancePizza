<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Validator;

class StoreDailyPayEntryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'date'                                  => ['required', 'date'],
            'lines'                                 => ['required', 'array', 'min:1'],
            'lines.*.technician_id'                 => ['required', 'integer', 'exists:technicians,id'],
            'lines.*.store_id'                      => ['required', 'integer', 'exists:stores,id'],
            'lines.*.total_working_hours'           => ['nullable', 'numeric', 'min:0'],
            'lines.*.gas'                           => ['nullable', 'numeric', 'min:0'],
            'lines.*.invoices'                      => ['nullable', 'numeric', 'min:0'],
            'lines.*.hourly_payment_rate'           => ['nullable', 'numeric', 'min:0'],
            'lines.*.money_owed'                    => ['nullable', 'numeric', 'min:0'],
            'lines.*.travel_time'                   => ['nullable', 'numeric', 'min:0'],
            'lines.*.total_break_time'              => ['nullable', 'numeric', 'min:0'],
            'lines.*.ticket_issue_ids'              => ['nullable', 'array'],
            'lines.*.ticket_issue_ids.*'            => ['integer', 'exists:ticket_issues,id'],
            'lines.*.notes'                         => ['nullable', 'array'],
            'lines.*.notes.*.body'                  => ['required_with:lines.*.notes.*', 'string'],
            'lines.*.notes.*.type'                  => ['nullable', 'string'],
            'lines.*.notes.*.files'                 => ['nullable', 'array'],
            'lines.*.notes.*.files.*'               => ['file', 'max:10240'],
            'lines.*.files'                         => ['nullable', 'array'],
            'lines.*.files.*'                       => ['file', 'max:10240'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v) {
            foreach ((array) $this->input('lines', []) as $i => $line) {
                $techId   = $line['technician_id'] ?? null;
                $issueIds = $line['ticket_issue_ids'] ?? [];

                if (! $techId || empty($issueIds)) {
                    continue;
                }

                $invalid = collect($issueIds)->filter(fn ($issueId) =>
                    ! DB::table('technician_ticket_issue')
                        ->where('technician_id', $techId)
                        ->where('ticket_issue_id', $issueId)
                        ->exists()
                )->values()->all();

                if (! empty($invalid)) {
                    $v->errors()->add(
                        "lines.{$i}.ticket_issue_ids",
                        'Technician is not assigned to issue(s): ' . implode(', ', $invalid) . '.'
                    );
                }
            }
        });
    }
}
