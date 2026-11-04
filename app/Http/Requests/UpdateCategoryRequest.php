<?php

namespace App\Http\Requests;

use App\Rules\UniqueName;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdateCategoryRequest extends FormRequest
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
            'name' => ['sometimes', 'required', 'string', 'max:255', new UniqueName('categories', 'technician category', $this->route('category')?->id)],
            'description' => ['sometimes', 'nullable', 'string', 'max:5000'],
        ];
    }
}
