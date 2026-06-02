<?php

namespace App\Http\Requests;

use App\Services\TicketIssueService;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

class AttachTechniciansRequest extends FormRequest
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
            'technician_ids' => ['required', 'array', 'min:1'],
            'technician_ids.*' => ['integer', 'exists:technicians,id'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(fn (Validator $v) => app(TicketIssueService::class)
            ->validateIssuesBelongToTicket($v, $this->route('ticket'), (array) $this->input('ticket_issue_ids', [])));
    }
}
