<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * `GET technician-purchases` filters.
 */
class TechnicianPurchaseIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'technician_id' => ['nullable', 'integer'],
            'trip_id' => ['nullable', 'integer'],
            'ticket_issue_id' => ['nullable', 'integer'],
            'unpaid' => ['nullable', 'boolean'],
        ];
    }
}
