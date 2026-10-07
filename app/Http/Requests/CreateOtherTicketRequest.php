<?php

namespace App\Http\Requests;

use App\Enums\Priority;
use App\Enums\TicketType;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Contracts\Validation\Validator;
use App\Http\Requests\Concerns\ValidatesTroubleshootingConfirmation;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CreateOtherTicketRequest extends FormRequest
{
    use ValidatesTroubleshootingConfirmation;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return $this->troubleshootingRules() + [
            'other_store' => ['required', 'string', 'max:255'],
            'type'        => ['nullable', Rule::enum(TicketType::class)],
            'issues'      => ['required', 'array', 'min:1'],
            'issues.*.issue_id'    => ['nullable', 'integer', 'exists:issues,id'],
            'issues.*.other_title' => ['nullable', 'string', 'max:255', 'required_without:issues.*.issue_id'],
            'issues.*.priority'    => ['required', Rule::enum(Priority::class)],
            'issues.*.description' => ['required', 'string'],
            'issues.*.notes'       => ['nullable', 'array'],
            'issues.*.notes.*.body'  => ['required_with:issues.*.notes.*', 'string', 'max:10000'],
            'issues.*.notes.*.type'  => ['nullable', 'string', 'max:255'],
            'issues.*.files'       => ['nullable', 'array'],
            'issues.*.files.*'     => ['file', 'max:10240'],
            'notes'          => ['nullable', 'array'],
            'notes.*.body'   => ['required_with:notes.*', 'string', 'max:10000'],
            'notes.*.type'   => ['nullable', 'string', 'max:255'],
            'files'          => ['nullable', 'array'],
            'files.*'        => ['file', 'max:10240'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $this->validateTroubleshootingConfirmation($validator);

            foreach ((array) $this->input('issues', []) as $i => $issue) {
                $hasId    = ! empty($issue['issue_id']);
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
