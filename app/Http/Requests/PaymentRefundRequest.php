<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * POST payments/{payment}/refunds: money that came back for a payment
 * (docs/MAINTENANCE.md §4, Refunds). The amounts are what came back, as
 * positive numbers; the refund's lines are stored negative.
 */
class PaymentRefundRequest extends FormRequest
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
            // When the money came back: not before it was paid (checked by
            // the service), not in the future.
            'refunded_at' => ['required', 'date', 'before_or_equal:today'],
            // How it came back, from the editable list (GET /payment-methods).
            'payment_method_id' => ['nullable', 'integer', 'exists:payment_methods,id'],
            'reference' => ['nullable', 'string', 'max:255'],
            // Why: returned, order cancelled, overcharged...
            'reason' => ['required', 'string', 'max:5000'],
            'lines' => ['required', 'array', 'min:1', 'max:200'],
            'lines.*.line_id' => ['required', 'integer'],
            'lines.*.amount' => ['required', 'numeric', 'gt:0', 'max:9999999999'],
        ];
    }
}
