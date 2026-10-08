<?php

namespace App\Http\Requests;

use App\Models\TroubleshootingGuide;
use App\Models\TroubleshootingStep;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * POST /api/issues/{issue}/troubleshooting-guides -- a new guide.
 * PUT  /api/troubleshooting-guides/{guide}         -- the whole guide, replaced.
 */
class UpsertTroubleshootingGuideRequest extends FormRequest
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
            // The specific problem this guide is for: "Won't heat".
            'title' => ['required', 'string', 'max:255'],
            // In order: what to try first, then next. A step keeps its id
            // (and its files) across edits. Blank rows (an editor's empty
            // line) are dropped, not refused.
            'steps' => ['required', 'array', 'min:1', 'max:30'],
            'steps.*' => ['array'],
            'steps.*.id' => ['nullable', 'integer'],
            'steps.*.body' => ['nullable', 'string', 'max:1000'],
            // A video, a PDF manual, a vendor page.
            'link_url' => ['nullable', 'url:http,https', 'max:2048'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v) {
            $steps = (array) $this->input('steps', []);

            $real = array_filter($steps, fn ($s) => is_array($s) && trim((string) ($s['body'] ?? '')) !== '');
            if ($real === [] && !$v->errors()->has('steps')) {
                $v->errors()->add('steps', 'Add at least one step to try.');
            }

            // A step id must be one of this guide's own steps. A new guide has
            // none, so any id on it is someone else's.
            $guide = $this->route('guide');
            $ids = collect($steps)->pluck('id')->filter()->map(fn ($id) => (int) $id);
            if ($ids->isEmpty()) {
                return;
            }

            $own = $guide instanceof TroubleshootingGuide
                ? TroubleshootingStep::query()->where('troubleshooting_guide_id', $guide->id)->pluck('id')->map(fn ($id) => (int) $id)
                : collect();

            foreach ($steps as $i => $step) {
                if (isset($step['id']) && !$own->contains((int) $step['id'])) {
                    $v->errors()->add("steps.{$i}.id", 'This step is not part of this guide.');
                }
            }
        });
    }
}
