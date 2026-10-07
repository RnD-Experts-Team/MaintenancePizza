<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ChecksTicketRecord;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class ChangeTechniciansRequest extends FormRequest
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
            'technician_ids' => ['required', 'array', 'min:1'],
            'technician_ids.*' => ['integer', 'exists:technicians,id'],
        ];
    }
}
