<?php

namespace App\Http\Requests;

use App\Rules\UniqueName;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Create (POST suppliers) or edit (PATCH suppliers/{supplier}) a supplier.
 * On an edit every field is optional; the name stays unique ignoring
 * capitals and repeated spaces, deleted suppliers included.
 */
class SupplierRequest extends FormRequest
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
        $supplier = $this->route('supplier');
        $editing = $supplier !== null;
        $sometimes = $editing ? ['sometimes'] : [];

        return [
            'name' => [...$sometimes, 'required', 'string', 'max:255', new UniqueName('suppliers', 'supplier', $supplier?->id)],
            'phone' => [...$sometimes, ...StoreTechnicianRequest::PHONE_RULES],
            'website' => [...$sometimes, 'nullable', 'string', 'max:255'],
            'description' => [...$sometimes, 'nullable', 'string', 'max:5000'],
        ];
    }
}
