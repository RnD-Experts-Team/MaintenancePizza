<?php

namespace App\Services;

use App\Models\Issue;
use App\Models\Technician;
use App\Models\TechnicianIssueAbility;
use App\Models\User;
use Closure;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Who is good at what, and who to call first.
 *
 * A technician gets 1-5 stars and notes per catalog issue ("great with Ovens,
 * slow on POS"); the issues they have an entry for are the issues they COVER.
 * One technician per issue can be the one to "call first". There is no typed
 * overall rating any more: it is the average of the per-issue stars
 * (Technician::averageRating()).
 * Pinning someone moves the pin off whoever had it.
 *
 * The database itself allows only one pin per issue (see the
 * migration), so two people pinning at the same moment cannot both win: the
 * loser gets a 409 and reloads.
 *
 * Read through its own endpoint, never through GET /technicians: store users
 * read the technician list, and the ratings are the coordinator's notes.
 */
class TechnicianAbilityService
{
    /**
     * Rate a technician on one issue -- the whole entry is replaced. An entry
     * with no stars, no notes and no pin still says "covers this issue, not
     * rated yet" (the dashboard's "Add issue"), so it is kept; DELETE is what
     * takes an issue off.
     *
     * @param  array{rating?: int|string|null, notes?: string|null, call_first?: bool|int|string}  $data
     * @return array{ability: TechnicianIssueAbility|null, moved_from: Technician|null}
     */
    public function setForIssue(Technician $technician, Issue $issue, array $data): array
    {
        $rating = $this->rating($data['rating'] ?? null);
        $notes = $this->text($data['notes'] ?? null);
        $callFirst = (bool) ($data['call_first'] ?? false);

        return $this->refusingRaces(fn () => DB::transaction(function () use ($technician, $issue, $rating, $notes, $callFirst) {
            $ability = TechnicianIssueAbility::query()
                ->where('technician_id', $technician->id)
                ->where('issue_id', $issue->id)
                ->lockForUpdate()
                ->first();

            $movedFrom = null;

            if ($callFirst && ! $ability?->call_first) {
                $holder = TechnicianIssueAbility::query()
                    ->where('issue_id', $issue->id)
                    ->where('call_first', true)
                    ->where('technician_id', '!=', $technician->id)
                    ->lockForUpdate()
                    ->first();

                if ($holder !== null) {
                    $movedFrom = $holder->technician;
                    $this->unpinAbility($holder);
                }
            }

            $ability ??= new TechnicianIssueAbility(['technician_id' => $technician->id, 'issue_id' => $issue->id]);
            $ability->rating = $rating;
            $ability->notes = $notes;
            $ability->call_first = $callFirst ? true : null;
            $ability->updated_by = Auth::id();
            $ability->save();

            return ['ability' => $ability->load('editor'), 'moved_from' => $movedFrom];
        }));
    }

    public function removeForIssue(Technician $technician, Issue $issue): void
    {
        TechnicianIssueAbility::query()
            ->where('technician_id', $technician->id)
            ->where('issue_id', $issue->id)
            ->delete();
    }

    /**
     * A deleted technician cannot be the one to call first for anything. The
     * stars and notes stay and come back on restore; the pins do not -- by
     * then someone else may well be the go-to.
     */
    public function clearPins(Technician $technician): void
    {
        TechnicianIssueAbility::query()
            ->where('technician_id', $technician->id)
            ->where('call_first', true)
            ->get()
            ->each(fn (TechnicianIssueAbility $ability) => $this->unpinAbility($ability));
    }

    /**
     * Everything the pickers and the editor need, in one read: every
     * per-issue entry (or one issue's). Deleted technicians are left out -- nobody can pick
     * them.
     *
     * @return array{by_issue: array<int, array<string, mixed>>}
     */
    public function board(?int $issueId = null): array
    {
        $byIssue = TechnicianIssueAbility::query()
            ->whereIn('technician_id', Technician::query()->select('id'))
            ->when($issueId !== null, fn ($q) => $q->where('issue_id', $issueId))
            ->with('editor')
            ->orderBy('issue_id')
            ->orderBy('technician_id')
            ->get()
            ->map(fn (TechnicianIssueAbility $a) => $this->presentAbility($a))
            ->values()
            ->all();

        return ['by_issue' => $byIssue];
    }

    /**
     * @return array<string, mixed>
     */
    public function presentAbility(TechnicianIssueAbility $ability): array
    {
        return [
            'technician_id' => $ability->technician_id,
            'issue_id' => $ability->issue_id,
            'rating' => $ability->rating,
            'notes' => $ability->notes,
            'call_first' => (bool) $ability->call_first,
            'updated_by' => $ability->updated_by,
            'editor' => $this->presentUser($ability->relationLoaded('editor') ? $ability->editor : null),
            'updated_at' => $ability->updated_at,
        ];
    }

    /**
     * @return array{id: int, name: string}|null
     */
    public function presentMover(?Technician $technician): ?array
    {
        return $technician === null ? null : ['id' => $technician->id, 'name' => $technician->name];
    }

    /**
     * Take the pin off an entry; the technician still covers the issue.
     */
    private function unpinAbility(TechnicianIssueAbility $ability): void
    {
        $ability->call_first = null;
        $ability->updated_by = Auth::id();
        $ability->save();
    }

    /**
     * @template T
     *
     * @param  Closure(): T  $write
     * @return T
     */
    private function refusingRaces(Closure $write): mixed
    {
        try {
            return $write();
        } catch (UniqueConstraintViolationException) {
            abort(409, 'Someone else changed these ratings at the same moment. Reload and try again.');
        }
    }

    private function rating(mixed $value): ?int
    {
        return $value === null || $value === '' ? null : (int) $value;
    }

    private function text(mixed $value): ?string
    {
        $value = is_string($value) ? trim($value) : null;

        return $value === '' ? null : $value;
    }

    /**
     * @return array{id: int, name: string}|null
     */
    private function presentUser(?User $user): ?array
    {
        return $user === null ? null : ['id' => $user->id, 'name' => $user->name];
    }
}
