<?php

namespace App\Http\Requests;

use App\Enums\PartUsagePayer;
use App\Enums\PartUsageSource;
use App\Services\TicketIssueService;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorePartUsageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * A payload that only knows about the old shape — `{part_id, cost}` with no
     * quantity — still means something unambiguous: one of them, at that price,
     * bought by us. Normalise it rather than rejecting it, so the existing
     * clients keep working while they are updated.
     */
    protected function prepareForValidation(): void
    {
        if ($this->filled('cost') && ! $this->has('unit_cost')) {
            $this->merge([
                'quantity' => $this->input('quantity', 1),
                'unit_cost' => $this->input('cost'),
                'source' => $this->input('source', PartUsageSource::Purchased->value),
                'paid_by' => $this->input('paid_by', PartUsagePayer::Us->value),
            ]);
        }
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'ticket_issue_ids' => ['required', 'array', 'min:1'],
            'ticket_issue_ids.*' => ['integer'],
            'part_id' => ['required', 'integer', 'exists:parts,id'],
            'quantity' => ['required', 'numeric', 'gt:0'],
            // `cost` is derived server-side as quantity * unit_cost and is not
            // accepted from the client, beyond the legacy shim above.
            'unit_cost' => ['required', 'numeric', 'min:0'],
            'source' => ['required', Rule::enum(PartUsageSource::class)],
            'paid_by' => ['required', Rule::enum(PartUsagePayer::class)],
            'paid_by_technician_id' => ['nullable', 'required_if:paid_by,technician', 'integer', 'exists:technicians,id'],
            'storage_location_id' => ['nullable', 'required_if:source,from_storage', 'integer', 'exists:storage_locations,id'],
            'returned_quantity' => ['nullable', 'numeric', 'min:0', 'lte:quantity'],
            'returned_to_storage_location_id' => ['nullable', 'integer', 'exists:storage_locations,id'],
            // Vendor, receipt number and the like live here rather than in a
            // column — nothing aggregates by vendor.
            'notes' => ['nullable', 'array'],
            'notes.*.body' => ['required_with:notes.*', 'string'],
            'notes.*.type' => ['nullable', 'string'],
            'notes.*.files' => ['nullable', 'array'],
            'notes.*.files.*' => ['file', 'max:10240'],
            'files' => ['nullable', 'array'],
            'files.*' => ['file', 'max:10240'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v) {
            // Parts stay strictly scoped to their ticket; only attendance is
            // allowed to span tickets.
            app(TicketIssueService::class)->validateIssuesBelongToTicket(
                $v,
                $this->route('ticket'),
                (array) $this->input('ticket_issue_ids', [])
            );

            if ((float) $this->input('returned_quantity', 0) > 0 && ! $this->filled('returned_to_storage_location_id')) {
                $v->errors()->add(
                    'returned_to_storage_location_id',
                    'Say which location the unused parts went back to.'
                );
            }
        });
    }
}
