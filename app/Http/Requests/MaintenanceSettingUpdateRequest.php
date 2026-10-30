<?php

namespace App\Http\Requests;

use App\Services\MaintenanceSettingsService;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Change the maintenance settings (docs/MAINTENANCE-V2.md §2 "Settings"). Each
 * key is optional; a key left out keeps its value.
 *
 * approval_levels replaces the whole list: lowest level first, each with a
 * level key, a label and an exclusive max, maxes rising, only the last one
 * without a max (so every amount has someone who may approve it).
 */
class MaintenanceSettingUpdateRequest extends FormRequest
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
            'approval_levels' => ['sometimes', 'array', 'min:1', 'max:10'],
            'approval_levels.*' => ['array'],
            'approval_levels.*.level' => ['required', 'string', 'max:32', 'regex:/^[a-z0-9_]+$/'],
            'approval_levels.*.label' => ['required', 'string', 'max:100'],
            'approval_levels.*.max' => ['present', 'nullable', 'numeric', 'min:0', 'max:9999999'],
            'trip_link_idle_hours' => [
                'sometimes',
                'integer',
                'min:'.MaintenanceSettingsService::MIN_LINK_IDLE_HOURS,
                'max:'.MaintenanceSettingsService::MAX_LINK_IDLE_HOURS,
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'approval_levels.*.level.regex' => 'A level key is lower-case letters, digits and underscores, like "manager".',
            'trip_link_idle_hours.min' => 'A trip link must live at least :min hour.',
            'trip_link_idle_hours.max' => 'A trip link can live at most :max hours (30 days) without use.',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            if ($validator->errors()->isNotEmpty() || !is_array($this->input('approval_levels'))) {
                return;
            }

            foreach (MaintenanceSettingsService::shapeErrors($this->input('approval_levels')) as $field => $message) {
                $validator->errors()->add($field, $message);
            }
        });
    }
}
