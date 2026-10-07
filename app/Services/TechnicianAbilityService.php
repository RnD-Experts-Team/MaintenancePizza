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
 * slow on POS"), and the same overall. One technician per issue can be the
 * one to "call first", and one overall -- the person to ring for anything.
 * Pinning someone moves the pin off whoever had it.
 *
 * The database itself allows only one pin per issue and one overall (see the
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
     * left with no stars, no notes and no pin says nothing, so it is removed.
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

            if ($rating === null && $notes === null && ! $callFirst) {
                $ability?->delete();

                return ['ability' => null, 'moved_from' => null];
            }

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
     * The overall rating. Only the fields sent change.
     *
     * @param  array{rating?: int|string|null, notes?: string|null, call_first?: bool|int|string}  $data
     * @return array{technician: Technician, moved_from: Technician|null}
     */
    public function setOverall(Technician $technician, array $data): array
    {
        return $this->refusingRaces(fn () => DB::transaction(function () use ($technician, $data) {
            $technician = Technician::query()->lockForUpdate()->findOrFail($technician->id);
            $movedFrom = null;

            if (array_key_exists('rating', $data)) {
                $technician->rating = $this->rating($data['rating']);
            }

            if (array_key_exists('notes', $data)) {
                $technician->rating_notes = $this->text($data['notes']);
            }

            if (array_key_exists('call_first', $data)) {
                $callFirst = (bool) $data['call_first'];

                if ($callFirst && ! $technician->call_first) {
                    // withTrashed: a pin left on a deleted technician would
                    // still hold the unique index.
                    $holder = Technician::withTrashed()
                        ->where('call_first', true)
                        ->whereKeyNot($technician->id)
                        ->lockForUpdate()
                        ->first();

                    if ($holder !== null) {
                        $movedFrom = $holder;
                        $this->stampRating($holder, ['call_first' => null]);
                    }
                }

                $technician->call_first = $callFirst ? true : null;
            }

            if ($technician->isDirty(['rating', 'rating_notes', 'call_first'])) {
                $this->stampRating($technician, []);
            }

            return ['technician' => $technician->load('ratingEditor'), 'moved_from' => $movedFrom];
        }));
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

        if ($technician->call_first) {
            $this->stampRating($technician, ['call_first' => null]);
        }
    }

    /**
     * Everything the pickers and the editor need, in one read: the overall
     * rating of every technician that has one, and every per-issue entry
     * (or one issue's). Deleted technicians are left out -- nobody can pick
     * them.
     *
     * @return array{overall: array<int, array<string, mixed>>, by_issue: array<int, array<string, mixed>>}
     */
    public function board(?int $issueId = null): array
    {
        $overall = Technician::query()
            ->where(fn ($q) => $q->whereNotNull('rating')->orWhereNotNull('rating_notes')->orWhereNotNull('call_first'))
            ->with('ratingEditor')
            ->orderBy('id')
            ->get()
            ->map(fn (Technician $t) => $this->presentOverall($t))
            ->values()
            ->all();

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

        return ['overall' => $overall, 'by_issue' => $byIssue];
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
     * Same shape as a per-issue entry, without the issue.
     *
     * @return array<string, mixed>
     */
    public function presentOverall(Technician $technician): array
    {
        return [
            'technician_id' => $technician->id,
            'rating' => $technician->rating,
            'notes' => $technician->rating_notes,
            'call_first' => (bool) $technician->call_first,
            'updated_by' => $technician->rating_updated_by,
            'editor' => $this->presentUser($technician->relationLoaded('ratingEditor') ? $technician->ratingEditor : null),
            'updated_at' => $technician->rating_updated_at,
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
     * Take the pin off an entry; an entry that was only the pin goes away.
     */
    private function unpinAbility(TechnicianIssueAbility $ability): void
    {
        if ($ability->rating === null && $ability->notes === null) {
            $ability->delete();

            return;
        }

        $ability->call_first = null;
        $ability->updated_by = Auth::id();
        $ability->save();
    }

    /**
     * @param  array<string, mixed>  $changes
     */
    private function stampRating(Technician $technician, array $changes): void
    {
        $technician->forceFill([
            ...$changes,
            'rating_updated_by' => Auth::id(),
            'rating_updated_at' => now(),
        ])->save();
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
