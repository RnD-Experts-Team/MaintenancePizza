<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Where a part sits inside its location.
 *
 * The COMPLETE address, every time: a level left out of `place_value_ids` is
 * cleared. A part has one address per location, so there is no partial update
 * that means anything -- and an empty array is the honest way to say "we no
 * longer know", which is different from never having said.
 *
 * Whether each value belongs to a level of THIS balance's location is checked
 * in StockService::setBalancePlace(), where the balance is in hand.
 */
class SetStockBalancePlaceRequest extends FormRequest
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
            'place_value_ids' => ['present', 'array'],
            'place_value_ids.*' => ['integer', 'exists:storage_place_values,id'],
        ];
    }
}
