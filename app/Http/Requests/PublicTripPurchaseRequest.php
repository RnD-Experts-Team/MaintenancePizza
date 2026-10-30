<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Technician link: something he bought with his own money. The supplier by id, or by name (a new name creates it).
 */
class PublicTripPurchaseRequest extends FormRequest
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
            ...self::purchaseRules(),
        ];
    }

    /**
     * The fields a purchase has wherever it is recorded from (also
     * TechnicianPurchaseStoreRequest).
     *
     * @return array<string, mixed>
     */
    public static function purchaseRules(): array
    {
        return [
            'description' => ['required', 'string', 'max:255'],
            'part_id' => ['nullable', 'integer', Rule::exists('parts', 'id')->whereNull('deleted_at')],
            'quantity' => ['nullable', 'numeric', 'gt:0', 'max:9999999999'],
            'total_cost' => ['required', 'numeric', 'min:0', 'max:9999999999'],
            'supplier_id' => ['nullable', 'integer', Rule::exists('suppliers', 'id')->whereNull('deleted_at'), 'prohibits:supplier_name'],
            'supplier_name' => ['nullable', 'string', 'max:255'],
            'ticket_issue_id' => ['nullable', 'integer', 'exists:ticket_issues,id'],
            'serial_number' => ['nullable', 'string', 'max:255'],
            'receipt' => ['nullable', 'array'],
            'receipt.*' => ['file', 'max:10240'],
        ];
    }
}
