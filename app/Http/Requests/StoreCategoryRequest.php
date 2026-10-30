<?php

namespace App\Http\Requests;

use App\Rules\UniqueName;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreCategoryRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:255', new UniqueName('categories', 'technician category')],
            'description' => ['nullable', 'string', 'max:5000'],
        ];
    }
}
