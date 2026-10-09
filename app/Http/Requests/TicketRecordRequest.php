<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ChecksTicketRecord;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The leaf actions that take no body (marking a record mistaken): only the
 * check that the record in the URL is on the ticket in the URL.
 */
class TicketRecordRequest extends FormRequest
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
        return [];
    }
}
