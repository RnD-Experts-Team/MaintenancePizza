<?php

namespace App\Http\Requests;

use App\Models\Attachment;
use App\Models\DailyPayEntry;
use App\Models\DailyPayLine;
use App\Models\DailyPayPayment;
use App\Models\Note;
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

            // EDIT ONLY. The notes and files already on the sheet that this
            // payment keeps. An edit rebuilds every payment and line, and
            // anything not listed here is removed -- so the client lists what
            // it still shows, instead of re-uploading files it never had.
            // A kept note keeps its own files, author and date.
            'payments.*.keep_note_ids' => ['nullable', 'array'],
            'payments.*.keep_note_ids.*' => ['integer', 'distinct'],
            'payments.*.keep_attachment_ids' => ['nullable', 'array'],
            'payments.*.keep_attachment_ids.*' => ['integer', 'distinct'],

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
            // EDIT ONLY -- as on the payment, for this line's notes and files.
            'payments.*.lines.*.keep_note_ids' => ['nullable', 'array'],
            'payments.*.lines.*.keep_note_ids.*' => ['integer', 'distinct'],
            'payments.*.lines.*.keep_attachment_ids' => ['nullable', 'array'],
            'payments.*.lines.*.keep_attachment_ids.*' => ['integer', 'distinct'],
        ];
    }

    /**
     * Every kept note / attachment id in the payload, by level.
     *
     * @return array{payment_notes: array<string, int>, payment_attachments: array<string, int>, line_notes: array<string, int>, line_attachments: array<string, int>}
     *         Each map is "error key" => id, so a refusal can point at the field.
     */
    private function keptIds(): array
    {
        $out = ['payment_notes' => [], 'payment_attachments' => [], 'line_notes' => [], 'line_attachments' => []];

        foreach ((array) $this->input('payments', []) as $p => $payment) {
            foreach ((array) ($payment['keep_note_ids'] ?? []) as $i => $id) {
                $out['payment_notes']["payments.{$p}.keep_note_ids.{$i}"] = (int) $id;
            }
            foreach ((array) ($payment['keep_attachment_ids'] ?? []) as $i => $id) {
                $out['payment_attachments']["payments.{$p}.keep_attachment_ids.{$i}"] = (int) $id;
            }
            foreach ((array) ($payment['lines'] ?? []) as $l => $line) {
                foreach ((array) ($line['keep_note_ids'] ?? []) as $i => $id) {
                    $out['line_notes']["payments.{$p}.lines.{$l}.keep_note_ids.{$i}"] = (int) $id;
                }
                foreach ((array) ($line['keep_attachment_ids'] ?? []) as $i => $id) {
                    $out['line_attachments']["payments.{$p}.lines.{$l}.keep_attachment_ids.{$i}"] = (int) $id;
                }
            }
        }

        return $out;
    }

    /**
     * Kept ids must be live notes / attachments of THIS entry, at the same
     * level (a payment's on a payment, a line's on a line), each kept once.
     * Anything else is refused: an id from another sheet would otherwise be
     * quietly moved onto this one.
     */
    private function validateKeptIds(Validator $v): void
    {
        $kept = $this->keptIds();
        $entry = $this->route('dailyPayEntry');

        if (!$entry instanceof DailyPayEntry) {
            // Creating: there is nothing on the sheet to keep yet.
            foreach ($kept as $map) {
                foreach (array_keys($map) as $key) {
                    $v->errors()->add($key, 'There is nothing to keep on a new pay sheet.');
                }
            }

            return;
        }

        $paymentIds = DailyPayPayment::query()->where('daily_pay_entry_id', $entry->id)->pluck('id')->all();
        $lineIds = DailyPayLine::query()->where('daily_pay_entry_id', $entry->id)->pluck('id')->all();

        $checks = [
            'payment_notes' => [Note::class, 'notable', DailyPayPayment::class, $paymentIds],
            'payment_attachments' => [Attachment::class, 'attachable', DailyPayPayment::class, $paymentIds],
            'line_notes' => [Note::class, 'notable', DailyPayLine::class, $lineIds],
            'line_attachments' => [Attachment::class, 'attachable', DailyPayLine::class, $lineIds],
        ];

        foreach ($checks as $level => [$model, $morph, $ownerClass, $ownerIds]) {
            $ids = array_values(array_unique($kept[$level]));
            if ($ids === []) {
                continue;
            }

            $valid = $model::query()
                ->whereKey($ids)
                ->where("{$morph}_type", $ownerClass)
                ->whereIn("{$morph}_id", $ownerIds === [] ? [0] : $ownerIds)
                ->pluck('id')
                ->map(fn ($id) => (int) $id)
                ->all();

            foreach ($kept[$level] as $key => $id) {
                if (!in_array($id, $valid, true)) {
                    $v->errors()->add($key, "Item {$id} is not on this pay sheet, so it cannot be kept.");
                }
            }
        }

        // Each one may be kept by one payment/line only -- the second keep
        // would silently take it away from the first.
        foreach (['note' => ['payment_notes', 'line_notes'], 'attachment' => ['payment_attachments', 'line_attachments']] as $what => $levels) {
            $seen = [];
            foreach ($levels as $level) {
                foreach ($kept[$level] as $key => $id) {
                    if (isset($seen[$id])) {
                        $v->errors()->add($key, "The same {$what} ({$id}) is kept twice.");
                    }
                    $seen[$id] = true;
                }
            }
        }
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
            $this->validateKeptIds($v);

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
