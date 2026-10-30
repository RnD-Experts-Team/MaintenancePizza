<?php

namespace App\Http\Requests;

use App\Models\Part;
use App\Rules\UniqueName;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdatePartRequest extends FormRequest
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
            'name' => ['sometimes', 'required', 'string', 'max:255', new UniqueName('parts', 'part', $this->route('part')?->id)],
            'description' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'brand' => ['sometimes', 'nullable', 'string', 'max:255'],
            'model' => ['sometimes', 'nullable', 'string', 'max:255'],
            'part_number' => ['sometimes', 'nullable', 'string', 'max:255'],
            'unit' => ['sometimes', 'string', Rule::in(Part::UNITS)],
            'track_serials' => ['sometimes', 'boolean'],
        ];
    }
}
