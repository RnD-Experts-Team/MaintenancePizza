<?php

namespace App\Http\Requests\Concerns;

use App\Models\Issue;
use App\Services\TroubleshootingService;
use Illuminate\Validation\Validator;

/**
 * The troubleshooting gate on opening a ticket.
 *
 * When a reported catalog issue has troubleshooting guides, the manager goes
 * through them on the issue's troubleshooting page first and says how it went
 * (owner, 2026-10-08):
 *
 *   tried       -- "I tried these steps", still broken
 *   none_match  -- "None of these describe my problem"
 *
 * ("This fixed it" never reaches here: no ticket is opened for it.) Naming
 * which guide they tried is optional; when they do, it must be one of this
 * issue's guides, and the version they read must still be current -- a
 * confirmation for steps that have since changed is for steps that are no
 * longer the steps.
 *
 * Issues without guides (or whose guides have no steps) and free-text "Other"
 * issues are never gated.
 */
trait ValidatesTroubleshootingConfirmation
{
    /**
     * @return array<string, array<int, string>>
     */
    protected function troubleshootingRules(): array
    {
        return [
            'issues.*.troubleshooting' => ['sometimes', 'nullable', 'string', 'in:tried,none_match'],
            'issues.*.troubleshooting_guide_id' => ['sometimes', 'nullable', 'integer'],
            'issues.*.troubleshooting_version' => ['sometimes', 'nullable', 'integer', 'min:1'],
        ];
    }

    protected function validateTroubleshootingConfirmation(Validator $validator): void
    {
        $lines = (array) $this->input('issues', []);
        $issueIds = collect($lines)->pluck('issue_id')->filter()->map(fn ($id) => (int) $id)->unique()->values()->all();

        if ($issueIds === []) {
            return;
        }

        $guides = app(TroubleshootingService::class)->gatingGuides($issueIds)->get()->groupBy('issue_id');
        $titles = Issue::withTrashed()->whereIn('id', $guides->keys())->pluck('title', 'id');

        foreach ($lines as $i => $line) {
            $issueId = (int) ($line['issue_id'] ?? 0);
            $own = $guides->get($issueId);
            if ($own === null || $own->isEmpty()) {
                continue;
            }

            $title = $titles[$issueId] ?? 'this issue';

            if (!in_array($line['troubleshooting'] ?? null, ['tried', 'none_match'], true)) {
                $validator->errors()->add(
                    "issues.{$i}.troubleshooting",
                    "Go through the troubleshooting for {$title} before opening a ticket."
                );

                continue;
            }

            $guideId = $line['troubleshooting_guide_id'] ?? null;
            if ($guideId === null) {
                continue;
            }

            $guide = $own->firstWhere('id', (int) $guideId);
            if ($guide === null) {
                $validator->errors()->add("issues.{$i}.troubleshooting_guide_id", "This guide is not one of the guides for {$title}.");

                continue;
            }

            if (isset($line['troubleshooting_version']) && (int) $line['troubleshooting_version'] !== (int) $guide->version) {
                $validator->errors()->add(
                    "issues.{$i}.troubleshooting_version",
                    "The troubleshooting steps for {$title} were just updated. Read them again, then confirm."
                );
            }
        }
    }
}
