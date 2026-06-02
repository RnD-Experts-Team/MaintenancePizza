<?php

namespace App\Http\Requests;

use App\Enums\Priority;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreTicketRequest extends FormRequest
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
            'issues' => ['required', 'array', 'min:1'],
            // Each line is either a catalog issue OR a free-text "other".
            'issues.*.issue_id' => ['nullable', 'integer', 'exists:issues,id'],
            'issues.*.other_title' => ['nullable', 'string', 'max:255', 'required_without:issues.*.issue_id'],
            'issues.*.priority' => ['required', Rule::enum(Priority::class)],
            'issues.*.description' => ['required', 'string'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            foreach ((array) $this->input('issues', []) as $i => $issue) {
                $hasId = ! empty($issue['issue_id']);
                $hasOther = ! empty($issue['other_title']);

                if ($hasId && $hasOther) {
                    $validator->errors()->add(
                        "issues.$i.issue_id",
                        'Provide either an issue_id or an other_title, not both.'
                    );
                }
            }
        });
    }
}
