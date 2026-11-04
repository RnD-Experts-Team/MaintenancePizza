<?php

namespace App\Http\Requests;

use App\Rules\UniqueName;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/** Add or rename a payment method (the catalog list behind "How we paid"). */
class PaymentMethodRequest extends FormRequest
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
        $editing = $this->route('paymentMethod');

        return [
            'name' => [$editing ? 'sometimes' : 'required', 'required', 'string', 'max:100', new UniqueName('payment_methods', 'payment method', $editing?->id)],
            'sort' => ['sometimes', 'integer', 'min:0', 'max:9999'],
        ];
    }
}
