<?php

namespace App\Http\Requests;

use App\Services\TicketIssueService;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

class StorePartUsageRequest extends FormRequest
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
            'part_id' => ['required', 'integer', 'exists:parts,id'],
            'cost' => ['required', 'numeric', 'min:0'],
            'files' => ['nullable', 'array'],
            'files.*' => ['file', 'max:10240'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(fn (Validator $v) => app(TicketIssueService::class)
            ->validateIssuesBelongToTicket($v, $this->route('ticket'), (array) $this->input('ticket_issue_ids', [])));
    }
}
