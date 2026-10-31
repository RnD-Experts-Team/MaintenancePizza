<?php

namespace App\Http\Requests;

use App\Enums\PaymentLineType;
use App\Enums\PaymentMethod;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * POST payments and POST payments/{payment}/edit (docs/MAINTENANCE.md §4).
 *
 * JSON, or multipart (so files can ride along) with `lines` either in
 * bracket notation or as ONE JSON string. A line's own files need bracket
 * notation (`lines[0][files][]`). The shape is checked here; the
 * rules that need the database state -- a session already paid, a purchase
 * already paid back, a serial already recorded, where each line was spent --
 * are PaymentService's, inside the write's transaction.
 */
class PaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $lines = $this->input('lines');

        if (is_string($lines)) {
            $decoded = json_decode($lines, true);
            $this->merge(['lines' => is_array($decoded) ? $decoded : $lines]);
        }
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $editing = $this->route('payment') !== null;

        return [
            'expected_updated_at' => [$editing ? 'required' : 'prohibited', 'date'],
            'paid_at' => ['required', 'date'],
            'technician_id' => ['nullable', 'integer', 'exists:technicians,id'],
            'supplier_id' => ['nullable', 'integer', 'exists:suppliers,id'],
            'method' => ['nullable', Rule::in(PaymentMethod::values())],
            'reference' => ['nullable', 'string', 'max:255'],
            'invoice_number' => ['nullable', 'string', 'max:255'],
            'note' => ['nullable', 'string', 'max:10000'],
            // As many notes as wanted; blank ones are dropped.
            'notes' => ['nullable', 'array', 'max:50'],
            'notes.*' => ['nullable', 'string', 'max:10000'],
            'files' => ['nullable', 'array'],
            'files.*' => ['file', 'max:10240'],
            // Edit only: the payment's own notes and files to take off.
            'remove_note_ids' => [$editing ? 'nullable' : 'prohibited', 'array'],
            'remove_note_ids.*' => ['integer', 'distinct'],
            'remove_attachment_ids' => [$editing ? 'nullable' : 'prohibited', 'array'],
            'remove_attachment_ids.*' => ['integer', 'distinct'],

            'lines' => ['required', 'array', 'min:1', 'max:200'],
            'lines.*' => ['array'],
            'lines.*.type' => ['required', Rule::in(PaymentLineType::values())],
            'lines.*.store_id' => ['nullable', 'integer', 'exists:stores,id'],
            'lines.*.other_store' => ['nullable', 'string', 'max:255'],
            'lines.*.storage_location_id' => ['nullable', 'integer', 'exists:storage_locations,id'],
            'lines.*.description' => ['nullable', 'string', 'max:2000'],
            'lines.*.amount' => ['nullable', 'numeric', 'min:0', 'max:9999999999'],
            'lines.*.hours' => ['nullable', 'numeric', 'min:0', 'max:999999'],
            'lines.*.rate' => ['nullable', 'numeric', 'min:0', 'max:99999999'],
            'lines.*.trip_session_ids' => ['nullable', 'array'],
            'lines.*.trip_session_ids.*' => ['integer', 'distinct', 'exists:trip_sessions,id'],
            'lines.*.ticket_issue_ids' => ['nullable', 'array'],
            'lines.*.ticket_issue_ids.*' => ['integer', 'distinct', 'exists:ticket_issues,id'],
            'lines.*.part_id' => ['nullable', 'integer', 'exists:parts,id'],
            'lines.*.quantity' => ['nullable', 'numeric', 'gt:0', 'max:9999999999'],
            'lines.*.unit_cost' => ['nullable', 'numeric', 'min:0', 'max:99999999'],
            'lines.*.bought_from_supplier_id' => ['nullable', 'integer', 'exists:suppliers,id'],
            'lines.*.destination' => ['nullable', Rule::in(['storage', 'ticket'])],
            'lines.*.place' => ['nullable', 'array'],
            'lines.*.place.*.level_id' => ['required', 'integer'],
            'lines.*.place.*.value_id' => ['required', 'integer'],
            'lines.*.serials' => ['nullable', 'array'],
            'lines.*.serials.*' => ['string', 'max:255'],
            'lines.*.technician_purchase_ids' => ['nullable', 'array'],
            'lines.*.technician_purchase_ids.*' => ['integer', 'distinct', 'exists:technician_purchases,id'],
            'lines.*.miles' => ['nullable', 'numeric', 'min:0', 'max:9999999'],
            'lines.*.issue_inspection_id' => ['nullable', 'integer', 'exists:issue_inspections,id'],
            // The line's own notes and files (as many as wanted).
            'lines.*.notes' => ['nullable', 'array', 'max:50'],
            'lines.*.notes.*' => ['nullable', 'string', 'max:10000'],
            'lines.*.files' => ['nullable', 'array', 'max:20'],
            'lines.*.files.*' => ['file', 'max:10240'],
            // Edit only: the saved notes and files (of the lines being
            // replaced) this line keeps. Whatever no line keeps is removed.
            'lines.*.keep_note_ids' => [$editing ? 'nullable' : 'prohibited', 'array'],
            'lines.*.keep_note_ids.*' => ['integer'],
            'lines.*.keep_attachment_ids' => [$editing ? 'nullable' : 'prohibited', 'array'],
            'lines.*.keep_attachment_ids.*' => ['integer'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $technician = $this->filled('technician_id');
            $supplier = $this->filled('supplier_id');

            if ($technician && $supplier) {
                $validator->errors()->add('technician_id', 'A payment is paid to one technician or one supplier, not both.');
            } elseif (! $technician && ! $supplier) {
                $validator->errors()->add('technician_id', 'Say who was paid: a technician or a supplier.');
            }

            // A session or a purchase is paid by one line only; a kept note or
            // file goes to one line only.
            foreach (['trip_session_ids' => 'session', 'technician_purchase_ids' => 'purchase', 'keep_note_ids' => 'note', 'keep_attachment_ids' => 'file'] as $field => $thing) {
                $seen = [];
                foreach ((array) $this->input('lines', []) as $i => $line) {
                    foreach ((array) ($line[$field] ?? []) as $id) {
                        if (isset($seen[(int) $id])) {
                            $validator->errors()->add("lines.{$i}.{$field}", "The same {$thing} (#{$id}) is on two lines of this payment.");
                        }
                        $seen[(int) $id] = true;
                    }
                }
            }
        });
    }
}
