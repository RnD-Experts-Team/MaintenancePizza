<?php

namespace App\Http\Requests;

use App\Enums\IssueStatus;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Change the status by hand: any status to any status, with a reason.
 */
class IssueJumpRequest extends FormRequest
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
            'status' => ['required', Rule::enum(IssueStatus::class)],
            'reason' => ['required', 'string', 'max:5000'],
        ];
    }
}
