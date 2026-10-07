<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ChecksTicketRecord;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreAssignmentDelayRequest extends FormRequest
{
    use ChecksTicketRecord;

    public function authorize(): bool
    {
        $this->checkTicketRecord();

        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'new_date' => ['required', 'date'],
            'new_hour' => ['nullable', 'date_format:H:i'],
            'reason' => ['required', 'string'],
        ];
    }
}
