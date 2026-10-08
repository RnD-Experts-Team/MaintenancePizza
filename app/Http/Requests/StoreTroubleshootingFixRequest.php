<?php

namespace App\Http\Requests;

use App\Models\TroubleshootingGuide;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * POST /api/stores/{store}/troubleshooting-fixes -- "this fixed it": the store
 * solved the problem with troubleshooting, so no ticket is opened. Saying
 * which guide fixed it is optional.
 */
class StoreTroubleshootingFixRequest extends FormRequest
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
            'issue_id' => ['required', 'integer', 'exists:issues,id'],
            'troubleshooting_guide_id' => ['nullable', 'integer'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v) {
            if ($v->errors()->isNotEmpty() || $this->input('troubleshooting_guide_id') === null) {
                return;
            }

            $belongs = TroubleshootingGuide::query()
                ->whereKey((int) $this->input('troubleshooting_guide_id'))
                ->where('issue_id', (int) $this->input('issue_id'))
                ->exists();

            if (!$belongs) {
                $v->errors()->add('troubleshooting_guide_id', 'This guide is not one of the guides for this issue.');
            }
        });
    }
}
