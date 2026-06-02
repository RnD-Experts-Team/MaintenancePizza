<?php

namespace App\Http\Requests;

use App\Services\TicketIssueService;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

class StorePayEntryRequest extends FormRequest
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
            'base_pay' => ['nullable', 'numeric', 'min:0'],
            'performance_pay' => ['nullable', 'numeric', 'min:0'],
            'driving_time' => ['nullable', 'numeric', 'min:0'],
            'miles_driven' => ['nullable', 'numeric', 'min:0'],
            'per_mile_rate' => ['nullable', 'numeric', 'min:0'],
            'driving_base_pay' => ['nullable', 'numeric', 'min:0'],
            'driving_performance_pay' => ['nullable', 'numeric', 'min:0'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(fn (Validator $v) => app(TicketIssueService::class)
            ->validateIssuesBelongToTicket($v, $this->route('ticket'), (array) $this->input('ticket_issue_ids', [])));
    }
}
