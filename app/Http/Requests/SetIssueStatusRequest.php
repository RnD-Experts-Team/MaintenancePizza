<?php

namespace App\Http\Requests;

use App\Enums\IssueStatus;
use App\Services\TicketIssueService;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SetIssueStatusRequest extends FormRequest
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
            // Deferral and cancellation each have their own endpoint (both need a
            // reason; deferral additionally spawns a child).
            'status' => [
                'required',
                Rule::enum(IssueStatus::class),
                Rule::notIn([IssueStatus::Deferred->value, IssueStatus::Cancelled->value]),
            ],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(fn (Validator $v) => app(TicketIssueService::class)
            ->validateIssuesBelongToTicket($v, $this->route('ticket'), (array) $this->input('ticket_issue_ids', [])));
    }
}
