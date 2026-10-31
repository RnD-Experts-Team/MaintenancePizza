<?php

namespace App\Http\Requests;

use App\Enums\StockMovementType;
use App\Models\Part;
use App\Models\StockBalance;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreStockMovementRequest extends FormRequest
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
            'moved_at' => ['required', 'date'],
            // Only what a person records by hand. Bought comes from a parts
            // payment line, stock_count from applying a count, undone from
            // marking a movement mistaken.
            'type' => ['required', Rule::in(array_map(fn (StockMovementType $t) => $t->value, StockMovementType::manual()))],
            'storage_location_id' => ['nullable', 'integer', 'exists:storage_locations,id'],
            // Old history fields; who paid is recorded on the payment instead.
            'paid_by' => ['nullable', Rule::in(['us', 'technician'])],
            'paid_by_technician_id' => ['nullable', 'required_if:paid_by,technician', 'integer', 'exists:technicians,id'],
            // The ticket issue the parts were used on -- only on a `used` movement.
            'ticket_issue_id' => ['nullable', 'integer', 'exists:ticket_issues,id', Rule::prohibitedIf(fn () => $this->input('type') !== StockMovementType::Used->value)],
            'body' => ['nullable', 'string'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.part_id' => ['required', 'integer', 'exists:parts,id'],
            'lines.*.storage_location_id' => ['required', 'integer', 'exists:storage_locations,id'],
            'lines.*.quantity' => ['required', 'numeric', 'gt:0'],
            'lines.*.direction' => ['nullable', 'integer', 'in:-1,1'],
            'lines.*.unit_cost' => ['nullable', 'numeric', 'min:0'],
            // Serial-tracked parts name each piece: one serial per unit of quantity.
            'lines.*.serials' => ['nullable', 'array'],
            'lines.*.serials.*' => ['string', 'max:255'],
            'notes' => ['nullable', 'array'],
            'notes.*.body' => ['required_with:notes.*', 'string'],
            'notes.*.type' => ['nullable', 'string'],
            'notes.*.files' => ['nullable', 'array'],
            'notes.*.files.*' => ['file', 'max:10240'],
            'files' => ['nullable', 'array'],
            'files.*' => ['file', 'max:10240'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $type = StockMovementType::tryFrom((string) $this->input('type'));

            if (! $type) {
                return;
            }

            foreach ((array) $this->input('lines', []) as $i => $line) {
                // A type that moves both ways (moved) needs each
                // line to say which way it goes; the rest take it from the type.
                if ($type->defaultDirection() === 0 && ! isset($line['direction'])) {
                    $validator->errors()->add(
                        "lines.{$i}.direction",
                        "A {$type->label()} movement must state a direction (-1 out, 1 in) on every line."
                    );

                    continue;
                }

                $this->checkAvailableStock($validator, $type, $i, $line);
                $this->checkSerials($validator, $i, $line);
            }
        });
    }

    /**
     * A serial-tracked part needs exactly one serial per piece; other parts
     * take none. Whether each unit is really where the line says is checked
     * by StockService when the movement is written.
     *
     * @param  array<string, mixed>  $line
     */
    private function checkSerials(Validator $validator, int|string $index, array $line): void
    {
        if (empty($line['part_id'])) {
            return;
        }

        $part = Part::query()->find($line['part_id']);
        $serials = array_filter((array) ($line['serials'] ?? []), fn ($s) => is_string($s) && trim($s) !== '');

        if ($part === null) {
            return;
        }

        if (! $part->track_serials) {
            if ($serials !== []) {
                $validator->errors()->add("lines.{$index}.serials", "{$part->name} is not tracked by serial number.");
            }

            return;
        }

        if (count(array_unique(array_map('trim', $serials))) !== count($serials)) {
            $validator->errors()->add("lines.{$index}.serials", 'The same serial number is on this line twice.');

            return;
        }

        $quantity = (string) ($line['quantity'] ?? '0');
        if (! is_numeric($quantity) || bccomp($quantity, (string) count($serials), 2) !== 0) {
            $validator->errors()->add(
                "lines.{$index}.serials",
                "{$part->name} is tracked by serial number: give one serial for each piece ({$quantity} needed, ".count($serials).' given).'
            );
        }
    }

    /**
     * ADVISORY ONLY — this exists so the user gets a useful message before the
     * write is attempted. It is inherently TOCTOU-vulnerable (two concurrent
     * draws can both pass it), so it is NOT the guard. The authoritative check
     * lives in StockService::applyLine(), after the balance row is locked. Do
     * not delete that one as redundant.
     *
     * @param  array<string, mixed>  $line
     */
    private function checkAvailableStock(Validator $validator, StockMovementType $type, int|string $index, array $line): void
    {
        $direction = (int) ($line['direction'] ?? $type->defaultDirection());

        if ($direction >= 0 || empty($line['part_id']) || empty($line['storage_location_id'])) {
            return;
        }

        $available = (string) (StockBalance::query()
            ->where('part_id', $line['part_id'])
            ->where('storage_location_id', $line['storage_location_id'])
            ->value('quantity') ?? '0');

        if (bccomp((string) ($line['quantity'] ?? '0'), $available, 2) > 0) {
            $validator->errors()->add(
                "lines.{$index}.quantity",
                "Only {$available} of this part are at that location."
            );
        }
    }
}
