<?php

namespace App\Http\Requests\Concerns;

use App\Models\TroubleshootingGuide;
use Illuminate\Validation\Validator;

/**
 * The troubleshooting gate on opening a ticket.
 *
 * When a reported catalog issue has a troubleshooting guide, the manager must
 * confirm they read the steps and tried them -- one tick, per the owner -- or
 * the ticket is refused.
 *
 * The form also sends the guide version it showed. If the guide changed in the
 * meantime, the confirmation was for steps that are no longer the steps, so
 * the manager is asked to read them again.
 *
 * Issues without a guide, guides with no steps, and free-text "Other" issues
 * are never gated.
 */
trait ValidatesTroubleshootingConfirmation
{
    /**
     * @return array<string, array<int, string>>
     */
    protected function troubleshootingRules(): array
    {
        return [
            'issues.*.troubleshooting_confirmed' => ['sometimes', 'boolean'],
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

        $guides = TroubleshootingGuide::query()->whereIn('issue_id', $issueIds)->with('issue')->get()->keyBy('issue_id');

        foreach ($lines as $i => $line) {
            $guide = $guides[(int) ($line['issue_id'] ?? 0)] ?? null;
            if ($guide === null || !$guide->hasSteps()) {
                continue;
            }

            $title = $guide->issue?->title ?? 'this issue';
            $confirmed = filter_var($line['troubleshooting_confirmed'] ?? false, FILTER_VALIDATE_BOOLEAN);

            if (!$confirmed) {
                $validator->errors()->add(
                    "issues.{$i}.troubleshooting_confirmed",
                    "Read the troubleshooting steps for {$title} and confirm you tried them before opening a ticket."
                );

                continue;
            }

            if ($confirmed && isset($line['troubleshooting_version']) && (int) $line['troubleshooting_version'] !== (int) $guide->version) {
                $validator->errors()->add(
                    "issues.{$i}.troubleshooting_version",
                    "The troubleshooting steps for {$title} were just updated. Read them again, then confirm."
                );
            }
        }
    }
}
