<?php

namespace App\Http\Controllers;

use App\Http\Requests\PaymentMethodRequest;
use App\Models\PaymentMethod;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;

/**
 * GET/POST/PATCH/DELETE /api/payment-methods -- the editable list a payment's
 * "How we paid" picks from. A method some payment uses is refused on delete.
 */
class PaymentMethodController extends Controller
{
    public function index(): array
    {
        return ['data' => PaymentMethod::query()->withCount('payments')->orderBy('sort')->orderBy('name')->get()->map(fn (PaymentMethod $m) => $this->present($m))->all()];
    }

    public function store(PaymentMethodRequest $request)
    {
        $method = new PaymentMethod($request->validated());
        $method->sort ??= (int) PaymentMethod::query()->max('sort') + 1;
        $method->created_by = $request->user()?->id;
        $method->save();

        return response()->json(['data' => $this->present($method->loadCount('payments'))], 201);
    }

    public function update(PaymentMethodRequest $request, PaymentMethod $paymentMethod): array
    {
        $paymentMethod->fill($request->validated())->save();

        return ['data' => $this->present($paymentMethod->loadCount('payments'))];
    }

    public function destroy(PaymentMethod $paymentMethod): Response
    {
        $used = $paymentMethod->payments()->count();
        if ($used > 0) {
            throw ValidationException::withMessages([
                'payment_method' => "{$used} payment(s) were made with {$paymentMethod->name}, so it can't be deleted. Rename it instead.",
            ]);
        }

        $paymentMethod->delete();

        return response()->noContent();
    }

    /** @return array<string, mixed> */
    private function present(PaymentMethod $method): array
    {
        return [
            'id' => $method->id,
            'name' => $method->name,
            'sort' => $method->sort,
            'payments_count' => $method->payments_count ?? null,
        ];
    }
}
