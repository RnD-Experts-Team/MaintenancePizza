<?php

namespace App\Services;

use App\Enums\InspectionStatus;
use App\Enums\IssueStatus;
use App\Enums\TripEventKind;
use App\Enums\TripStatus;
use App\Jobs\PublishOutboxEventJob;
use App\Models\IssueInspection;
use App\Models\MaintenanceSetting;
use App\Models\Store;
use App\Models\Technician;
use App\Models\Ticket;
use App\Models\TicketIssue;
use App\Models\Trip;
use App\Models\TripAudit;
use App\Models\TripEvent;
use App\Models\TripSession;
use App\Models\TripStop;
use App\Models\TripStopIssue;
use App\Models\User;
use App\Services\MaintenanceEvents\MaintenanceEventFactory;
use App\Services\MaintenanceEvents\MaintenanceOutboxService;
use App\Support\NameKey;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * The core of trips (docs/MAINTENANCE.md §3): the link, putting an issue on
 * a technician's trip, and what a session's time adds up to. Everything other
 * services call lives here; the trip endpoints build on it.
 *
 * THE LINK. A trip is reached by the technician through a random token in its
 * URL. Only sha256(token) is stored, so the link cannot be read back out of
 * the database: the plain token exists once, in the return value of
 * issueLink() (or scheduleIssue() when it creates the trip), and whoever gets
 * it must pass it on. Rotating it (issueLink again) kills the old link.
 *
 * A link works while the trip is open, not mistaken, and was used (or, never
 * used, created) within its `link_idle_hours`: a long job keeps it alive, a
 * forgotten one dies by itself.
 */
class TripService
{
    /** Length of the plain token: 40 URL-safe characters (A-Z, a-z, 0-9). */
    public const TOKEN_LENGTH = 40;

    /** Outbox subject when a technician ticks a work job done on his link. */
    public const WORK_DONE_EVENT = 'maintenance.issue.work_done';

    /** How long an idle link lives when maintenance_settings says nothing. */
    public const DEFAULT_LINK_IDLE_HOURS = 48;

    // ------------------------------------------------------------------ Link

    /**
     * A fresh plain token and the hash that is stored for it.
     *
     * @return array{token: string, hash: string}
     */
    public function newToken(): array
    {
        $token = Str::random(self::TOKEN_LENGTH);

        return ['token' => $token, 'hash' => self::hashToken($token)];
    }

    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }

    /**
     * Give the trip a new link and return its plain token -- the only time it
     * can be seen. The old link stops working. Issuing counts as use, so a
     * link re-sent for a trip that sat idle works again straight away.
     */
    public function issueLink(Trip $trip): string
    {
        ['token' => $token, 'hash' => $hash] = $this->newToken();

        $trip->forceFill(['token_hash' => $hash, 'last_used_at' => now()])->save();

        return $token;
    }

    /**
     * The trip a plain token opens, or null for a token nobody issued (or one
     * replaced since). Says nothing about whether the link still works: see
     * linkActive().
     */
    public function findByToken(string $token): ?Trip
    {
        if ($token === '') {
            return null;
        }

        return Trip::query()->where('token_hash', self::hashToken($token))->first();
    }

    /**
     * Whether the link still opens the trip: open, not mistaken, and used (or
     * created) within link_idle_hours.
     */
    public function linkActive(Trip $trip, ?CarbonInterface $at = null): bool
    {
        if ($trip->status !== TripStatus::Open || $trip->mistaken || $trip->token_hash === null) {
            return false;
        }

        $expiresAt = $this->linkExpiresAt($trip);

        return $expiresAt !== null && $expiresAt->greaterThan($at ?? now());
    }

    /**
     * When the link dies if nobody uses it again (null when the trip has no
     * link). A closed or mistaken trip's link is dead whatever this says.
     */
    public function linkExpiresAt(Trip $trip): ?Carbon
    {
        $from = $trip->last_used_at ?? $trip->created_at;

        if ($from === null || $trip->token_hash === null) {
            return null;
        }

        return Carbon::instance($from)->addHours((int) $trip->link_idle_hours);
    }

    /**
     * Every technician action through the link (opening it included) keeps it
     * alive.
     */
    public function markUsed(Trip $trip): void
    {
        $trip->forceFill(['last_used_at' => now()])->save();
    }

    public function defaultLinkIdleHours(): int
    {
        $hours = (int) MaintenanceSetting::get('trip_link_idle_hours', self::DEFAULT_LINK_IDLE_HOURS);

        return $hours > 0 ? $hours : self::DEFAULT_LINK_IDLE_HOURS;
    }

    // ----------------------------------------------------------- Scheduling

    /**
     * Put an issue on the technician's trip for that date: his OPEN trip
     * planned for that day if there is one, otherwise a new trip (with a new
     * link). The issue goes on the stop for its ticket's store (or other
     * store), which is added to the trip when it is not on it yet.
     *
     * `$time` is not stored on the trip -- trips have no hour; the caller keeps
     * it on the booking (Assignment::assigned_hour). `$instructions` are added
     * to the trip's instructions, which is what the technician reads.
     *
     * `token` is non-null only when this call created the trip: it is the new
     * link's plain token, to hand to the technician. An existing trip's link
     * is never rotated here (that would kill the link he already has).
     *
     * @param  string  $purpose  work | inspection
     * @return array{trip: Trip, stop: TripStop, token: string|null}
     */
    public function scheduleIssue(
        TicketIssue $issue,
        Technician $technician,
        string $date,
        ?string $time,
        string $purpose,
        ?string $instructions,
        User $by,
    ): array {
        return DB::transaction(function () use ($issue, $technician, $date, $purpose, $instructions, $by) {
            // Serialise per technician so two bookings for the same day at the
            // same moment land on ONE trip rather than creating two.
            Technician::withTrashed()->whereKey($technician->id)->lockForUpdate()->first();

            $day = Carbon::parse($date)->toDateString();
            $token = null;

            $trip = Trip::query()
                ->where('technician_id', $technician->id)
                ->where('status', TripStatus::Open->value)
                ->where('mistaken', false)
                ->whereDate('planned_date', $day)
                ->orderBy('id')
                ->first();

            if ($trip === null) {
                ['token' => $token, 'hash' => $hash] = $this->newToken();

                $trip = Trip::query()->create([
                    'technician_id' => $technician->id,
                    'status' => TripStatus::Open,
                    'planned_date' => $day,
                    'token_hash' => $hash,
                    'link_idle_hours' => $this->defaultLinkIdleHours(),
                    'instructions' => $this->text($instructions),
                    'created_by' => $by->id,
                ]);
            } elseif (($extra = $this->text($instructions)) !== null) {
                $trip->instructions = $trip->instructions === null || trim($trip->instructions) === ''
                    ? $extra
                    : $trip->instructions."\n\n".$extra;
                $trip->save();
            }

            $stop = $this->stopFor($trip, $issue);

            $this->attachIssue($stop, $issue, $purpose);

            return ['trip' => $trip->refresh(), 'stop' => $stop->refresh(), 'token' => $token];
        });
    }

    /**
     * The trip's stop for the store the issue's ticket is at, added (last)
     * when the trip does not go there yet. A ticket for an "other" store
     * matches a stop with the same other_store text.
     */
    public function stopFor(Trip $trip, TicketIssue $issue): TripStop
    {
        $ticket = $issue->ticket()->withTrashed()->firstOrFail();

        $query = TripStop::query()->where('trip_id', $trip->id)->where('mistaken', false);

        if ($ticket->store_id !== null) {
            $query->where('store_id', $ticket->store_id);
        } else {
            $query->whereNull('store_id')->where('other_store', $ticket->other_store);
        }

        return $query->orderBy('id')->first() ?? TripStop::query()->create([
            'trip_id' => $trip->id,
            'store_id' => $ticket->store_id,
            'other_store' => $ticket->store_id === null ? $ticket->other_store : null,
            'status' => 'planned',
            'sort_order' => (int) TripStop::query()->where('trip_id', $trip->id)->max('sort_order') + 1,
        ]);
    }

    /**
     * Put the issue on the stop for a purpose. Already there for the same
     * purpose: left as it is (a job marked done stays done). Already there for
     * another purpose (an inspection, now the work): the purpose changes and
     * the task starts again as pending.
     */
    public function attachIssue(TripStop $stop, TicketIssue $issue, string $purpose): TripStopIssue
    {
        $row = TripStopIssue::query()
            ->where('trip_stop_id', $stop->id)
            ->where('ticket_issue_id', $issue->id)
            ->first();

        if ($row !== null && $row->purpose === $purpose) {
            return $row;
        }

        return TripStopIssue::query()->updateOrCreate(
            ['trip_stop_id' => $stop->id, 'ticket_issue_id' => $issue->id],
            ['purpose' => $purpose, 'task_status' => 'pending', 'done_at' => null, 'not_done_reason' => null],
        );
    }

    // ------------------------------------------------------------ Durations

    /**
     * What a session's time adds up to, in whole minutes:
     *
     *   paid      = (clock_out − clock_in) − break      breaks are unpaid
     *   work      = paid − travel − parts_run
     *   travel, parts_run, break   the time inside each kind of span
     *
     * so work + travel + parts_run = paid, always. An open session (or an
     * open span) counts up to now. Mistaken events are ignored; events
     * outside the session are clipped to it; an end with no start, or a
     * second start while one is running, is ignored.
     *
     * Spans should never overlap (the trip link refuses it), but when they do
     * -- a correction typed in from the dashboard -- each moment counts once,
     * break first, then parts run, then travel, so nothing is paid twice.
     *
     * @return array{work: int, travel: int, parts_run: int, break: int, paid: int}
     */
    public function durations(TripSession $session, ?CarbonInterface $now = null): array
    {
        $start = $session->clock_in?->getTimestamp();
        $end = ($session->clock_out ?? $now ?? now())->getTimestamp();

        if ($start === null || $end <= $start) {
            return ['work' => 0, 'travel' => 0, 'parts_run' => 0, 'break' => 0, 'paid' => 0];
        }

        $seconds = ['break' => 0, 'parts_run' => 0, 'travel' => 0];
        $spans = $this->spans($session, $start, $end);

        // Sweep the elementary segments between every span edge and give
        // each one to the highest-precedence bucket active over it.
        $edges = [$start, $end];
        foreach ($spans as [, $from, $to]) {
            $edges[] = $from;
            $edges[] = $to;
        }
        $edges = array_values(array_unique($edges));
        sort($edges);

        for ($i = 0; $i < count($edges) - 1; $i++) {
            [$a, $b] = [$edges[$i], $edges[$i + 1]];

            foreach (array_keys($seconds) as $bucket) {
                foreach ($spans as [$spanBucket, $from, $to]) {
                    if ($spanBucket === $bucket && $from <= $a && $to >= $b) {
                        $seconds[$bucket] += $b - $a;

                        continue 3;
                    }
                }
            }
        }

        $span = intdiv($end - $start, 60);
        $break = intdiv($seconds['break'], 60);
        $travel = intdiv($seconds['travel'], 60);
        $partsRun = intdiv($seconds['parts_run'], 60);
        $paid = max(0, $span - $break);

        return [
            'work' => max(0, $paid - $travel - $partsRun),
            'travel' => $travel,
            'parts_run' => $partsRun,
            'break' => $break,
            'paid' => $paid,
        ];
    }

    /**
     * The minutes a session is paid for: its clock span minus its breaks.
     */
    public function paidMinutes(TripSession $session, ?CarbonInterface $now = null): int
    {
        return $this->durations($session, $now)['paid'];
    }

    /**
     * The session's spans as [bucket, from, to] unix seconds, clipped to
     * [start, end]; a span still open runs to `end`.
     *
     * @return array<int, array{0: string, 1: int, 2: int}>
     */
    private function spans(TripSession $session, int $start, int $end): array
    {
        $events = $session->relationLoaded('events')
            ? $session->events
            : $session->events()->get();

        $open = [];
        $spans = [];

        foreach ($events->filter(fn (TripEvent $e) => ! $e->mistaken)->sortBy(fn (TripEvent $e) => [$e->at->getTimestamp(), $e->id]) as $event) {
            /** @var TripEventKind $kind */
            $kind = $event->kind;
            $bucket = $kind->bucket();
            $at = min(max($event->at->getTimestamp(), $start), $end);

            if ($kind->opens()) {
                $open[$bucket] ??= $at;

                continue;
            }

            if (isset($open[$bucket])) {
                if ($at > $open[$bucket]) {
                    $spans[] = [$bucket, $open[$bucket], $at];
                }
                unset($open[$bucket]);
            }
        }

        foreach ($open as $bucket => $from) {
            if ($end > $from) {
                $spans[] = [$bucket, $from, $end];
            }
        }

        return $spans;
    }

    // ======================================================================
    //  The trip endpoints (docs/MAINTENANCE.md §3). Everything below is
    //  what the dashboard (MOS) and the technician's link do to a trip.
    //  Refusals are 422s keyed by the field at fault (or `status` for a step
    //  that does not fit), worded for the person reading them.
    // ======================================================================

    // ------------------------------------------------------- Reading state

    /**
     * The trip's live session (clocked in, not mistaken), if any.
     */
    public function openSession(Trip $trip): ?TripSession
    {
        return TripSession::query()
            ->where('trip_id', $trip->id)
            ->whereNull('clock_out')
            ->where('mistaken', false)
            ->latest('clock_in')
            ->first();
    }

    /**
     * The technician's live session on ANY trip, if any.
     */
    public function openSessionOf(int $technicianId): ?TripSession
    {
        return TripSession::query()
            ->where('technician_id', $technicianId)
            ->whereNull('clock_out')
            ->where('mistaken', false)
            ->first();
    }

    /**
     * The spans running in a session right now: [bucket => started_at].
     * Follows the same reading as durations(): mistaken events ignored, a
     * second start while one runs ignored, an end with no start ignored.
     *
     * @return array<string, Carbon>
     */
    public function openSpans(TripSession $session): array
    {
        $events = $session->relationLoaded('events') ? $session->events : $session->events()->get();
        $open = [];

        foreach ($events->filter(fn (TripEvent $e) => ! $e->mistaken)->sortBy(fn (TripEvent $e) => [$e->at->getTimestamp(), $e->id]) as $event) {
            $bucket = $event->kind->bucket();

            if ($event->kind->opens()) {
                $open[$bucket] ??= Carbon::instance($event->at);
            } else {
                unset($open[$bucket]);
            }
        }

        return $open;
    }

    /**
     * What the technician is doing on this trip, for his page:
     * off_clock | on_clock | on_break | travelling | parts_run.
     * A break wins over a parts run, a parts run over travel (the same order
     * durations() counts overlapping time in).
     */
    public function state(Trip $trip, ?TripSession $open = null): string
    {
        $open ??= $this->openSession($trip);

        if ($open === null) {
            return 'off_clock';
        }

        $spans = $this->openSpans($open);

        return match (true) {
            isset($spans['break']) => 'on_break',
            isset($spans['parts_run']) => 'parts_run',
            isset($spans['travel']) => 'travelling',
            default => 'on_clock',
        };
    }

    /**
     * The payment that pays this session, or null: a payment_line_trip_session
     * row whose payment is not mistaken. Uses loaded paymentLines.payment when
     * there, so a list does not query per row.
     */
    public function sessionPaymentId(TripSession $session): ?int
    {
        $lines = $session->relationLoaded('paymentLines')
            ? $session->paymentLines
            : $session->paymentLines()->with('payment')->get();

        foreach ($lines as $line) {
            if ($line->payment !== null && ! $line->payment->mistaken) {
                return (int) $line->payment->id;
            }
        }

        return null;
    }

    /**
     * Closed, not mistaken, on a trip that is not mistaken, and claimed by no
     * live (non-mistaken) payment.
     *
     * @return Builder<TripSession>
     */
    public function unpaidSessionsQuery(): Builder
    {
        return TripSession::query()
            ->whereNotNull('clock_out')
            ->where('mistaken', false)
            ->whereHas('trip', fn (Builder $q) => $q->where('mistaken', false))
            ->whereNotExists(function (QueryBuilder $q) {
                $q->selectRaw('1')
                    ->from('payment_line_trip_session as plts')
                    ->join('payment_lines as pl', 'pl.id', '=', 'plts.payment_line_id')
                    ->join('payments as p', 'p.id', '=', 'pl.payment_id')
                    ->whereColumn('plts.trip_session_id', 'trip_sessions.id')
                    ->where('p.mistaken', false);
            });
    }

    /**
     * The stores the technician may add a visit to from his link: stores of
     * open issues he is on, plus the stores he covers.
     *
     * @return Collection<int, Store>
     */
    public function availableStores(Trip $trip): Collection
    {
        $technicianId = $trip->technician_id;

        $fromIssues = Ticket::query()
            ->whereNotNull('store_id')
            ->whereHas('ticketIssues', fn (Builder $q) => $q
                ->whereNotIn('status', array_map(fn (IssueStatus $s) => $s->value, IssueStatus::terminal()))
                ->whereHas('technicians', fn (Builder $t) => $t->where('technicians.id', $technicianId)))
            ->pluck('store_id');

        $covered = DB::table('store_technician')->where('technician_id', $technicianId)->pluck('store_id');

        return Store::query()
            ->whereIn('id', $fromIssues->merge($covered)->unique()->values())
            ->orderBy('store_number')
            ->get();
    }

    // -------------------------------------------------- Dashboard: the trip

    /**
     * A trip made by hand from the dashboard, with its link.
     *
     * @param  array{technician_id: int, planned_date: string, instructions?: string|null, stops?: array<int, array<string, mixed>>}  $data
     * @return array{trip: Trip, token: string}
     */
    public function createTrip(array $data, User $by): array
    {
        return DB::transaction(function () use ($data, $by) {
            ['token' => $token, 'hash' => $hash] = $this->newToken();

            $trip = Trip::query()->create([
                'technician_id' => (int) $data['technician_id'],
                'status' => TripStatus::Open,
                'planned_date' => Carbon::parse($data['planned_date'])->toDateString(),
                'token_hash' => $hash,
                'link_idle_hours' => $this->defaultLinkIdleHours(),
                'instructions' => $this->text($data['instructions'] ?? null),
                'created_by' => $by->id,
            ]);

            foreach ($data['stops'] ?? [] as $i => $stop) {
                $this->addStop($trip, $stop, null, "stops.{$i}");
            }

            $this->audit($trip, 'trip', $trip->id, 'created', null, $this->snapTrip($trip), null, $by);

            return ['trip' => $trip->refresh(), 'token' => $token];
        });
    }

    /**
     * @param  array{planned_date?: string, instructions?: string|null, link_idle_hours?: int}  $data
     */
    public function updateTrip(Trip $trip, array $data, ?string $reason, User $by): Trip
    {
        return DB::transaction(function () use ($trip, $data, $reason, $by) {
            $before = $this->snapTrip($trip);

            if (array_key_exists('planned_date', $data)) {
                $trip->planned_date = Carbon::parse($data['planned_date'])->toDateString();
            }
            if (array_key_exists('instructions', $data)) {
                $trip->instructions = $this->text($data['instructions']);
            }
            if (array_key_exists('link_idle_hours', $data)) {
                $trip->link_idle_hours = (int) $data['link_idle_hours'];
            }

            if ($trip->isDirty()) {
                $trip->save();
                $this->audit($trip, 'trip', $trip->id, 'updated', $before, $this->snapTrip($trip), $reason, $by);
            }

            return $trip;
        });
    }

    /**
     * A new link for the trip (the old one stops working). The plain token is
     * never written to the audit.
     */
    public function rotateLink(Trip $trip, User $by): string
    {
        if ($trip->mistaken) {
            $this->refuse('status', 'This trip was marked as a mistake, so it has no link.');
        }
        if (! $trip->isOpen()) {
            $this->refuse('status', 'This trip is closed. Reopen it before sending a new link.');
        }

        return DB::transaction(function () use ($trip, $by) {
            $token = $this->issueLink($trip);
            $this->audit($trip, 'trip', $trip->id, 'link_issued', null, ['last_used_at' => $trip->last_used_at?->toIso8601String()], null, $by);

            return $token;
        });
    }

    public function closeTrip(Trip $trip, ?string $reason, User $by): Trip
    {
        if (! $trip->isOpen()) {
            $this->refuse('status', 'This trip is already closed.');
        }
        if ($this->openSession($trip) !== null) {
            $this->refuse('status', 'The technician is still clocked in on this trip. Clock him out first.');
        }

        return DB::transaction(function () use ($trip, $reason, $by) {
            $before = $this->snapTrip($trip);
            $trip->forceFill(['status' => TripStatus::Closed, 'closed_at' => now()])->save();
            $this->audit($trip, 'trip', $trip->id, 'closed', $before, $this->snapTrip($trip), $reason, $by);

            return $trip;
        });
    }

    /**
     * Open a closed trip again. Counts as use, so the link the technician
     * already has works again straight away.
     */
    public function reopenTrip(Trip $trip, ?string $reason, User $by): Trip
    {
        if ($trip->mistaken) {
            $this->refuse('status', 'This trip was marked as a mistake and cannot be reopened.');
        }
        if ($trip->isOpen()) {
            $this->refuse('status', 'This trip is already open.');
        }

        return DB::transaction(function () use ($trip, $reason, $by) {
            $before = $this->snapTrip($trip);
            $trip->forceFill(['status' => TripStatus::Open, 'closed_at' => null, 'last_used_at' => now()])->save();
            $this->audit($trip, 'trip', $trip->id, 'reopened', $before, $this->snapTrip($trip), $reason, $by);

            return $trip;
        });
    }

    /**
     * The whole trip was a mistake: it and its sessions stop counting (and
     * its link stops working). Refused while a payment covers its time.
     */
    public function markTripMistaken(Trip $trip, string $reason, User $by): Trip
    {
        if ($trip->mistaken) {
            $this->refuse('status', 'This trip is already marked as a mistake.');
        }

        $sessions = $trip->sessions()->with('paymentLines.payment')->get();
        foreach ($sessions as $session) {
            if ($this->sessionPaymentId($session) !== null) {
                $this->refuse('status', 'A payment covers time on this trip. Mark that payment as a mistake first.');
            }
        }

        return DB::transaction(function () use ($trip, $sessions, $reason, $by) {
            foreach ($sessions->where('mistaken', false) as $session) {
                $before = $this->snapSession($session);
                $session->mistaken = true;
                $session->save();
                $this->audit($trip, 'session', $session->id, 'mistaken', $before, $this->snapSession($session), $reason, $by);
            }

            $before = $this->snapTrip($trip);
            $trip->forceFill(['mistaken' => true])->save();
            $this->audit($trip, 'trip', $trip->id, 'mistaken', $before, $this->snapTrip($trip), $reason, $by);

            return $trip;
        });
    }

    // ------------------------------------------------- Dashboard: the stops

    /**
     * Add a store visit to the trip with the ticket issues to do there. The
     * store comes from store_id / other_store, or from the issues' ticket
     * when neither is given; every issue must be at that store. A stop for
     * the same store already on the trip is reused.
     *
     * @param  array{store_id?: int|null, other_store?: string|null, ticket_issue_ids?: array<int, int>, purpose?: string|null}  $data
     * @param  string  $field  the input key errors are reported under
     */
    public function addStop(Trip $trip, array $data, ?User $by, string $field = ''): TripStop
    {
        $prefix = $field === '' ? '' : "{$field}.";
        $issues = TicketIssue::query()
            ->with(['ticket' => fn ($q) => $q->withTrashed()])
            ->whereIn('id', array_map('intval', $data['ticket_issue_ids'] ?? []))
            ->get();

        $storeId = isset($data['store_id']) ? (int) $data['store_id'] : null;
        $other = $this->text($data['other_store'] ?? null);

        if ($storeId === null && $other === null) {
            $first = $issues->first();
            if ($first === null) {
                $this->refuse("{$prefix}store_id", 'Pick a store (or type where it is) for this visit.');
            }
            $storeId = $first->ticket->store_id;
            $other = $storeId === null ? $first->ticket->other_store : null;
        }

        foreach ($issues as $issue) {
            $matches = $storeId !== null
                ? $issue->ticket->store_id === $storeId
                : ($issue->ticket->store_id === null && NameKey::of($issue->ticket->other_store) === NameKey::of($other));

            if (! $matches) {
                $this->refuse("{$prefix}ticket_issue_ids", "\"{$issue->displayTitle()}\" is on a ticket for another store.");
            }
        }

        $purpose = $data['purpose'] ?? 'work';

        return DB::transaction(function () use ($trip, $storeId, $other, $issues, $purpose, $by) {
            $query = TripStop::query()->where('trip_id', $trip->id)->where('mistaken', false);
            $storeId !== null
                ? $query->where('store_id', $storeId)
                : $query->whereNull('store_id')->where('other_store', $other);

            $stop = $query->orderBy('id')->first();
            $created = $stop === null;

            $stop ??= TripStop::query()->create([
                'trip_id' => $trip->id,
                'store_id' => $storeId,
                'other_store' => $storeId === null ? $other : null,
                'status' => 'planned',
                'sort_order' => (int) TripStop::query()->where('trip_id', $trip->id)->max('sort_order') + 1,
            ]);

            foreach ($issues as $issue) {
                $this->attachIssue($stop, $issue, $purpose);
            }

            if ($by !== null) {
                $this->audit($trip, 'stop', $stop->id, $created ? 'created' : 'updated', null, [
                    'store_id' => $stop->store_id,
                    'other_store' => $stop->other_store,
                    'ticket_issue_ids' => $issues->pluck('id')->all(),
                    'purpose' => $purpose,
                ], null, $by);
            }

            return $stop->refresh();
        });
    }

    public function markStopMistaken(TripStop $stop, string $reason, User $by): TripStop
    {
        if ($stop->mistaken) {
            $this->refuse('status', 'This visit is already marked as a mistake.');
        }

        return DB::transaction(function () use ($stop, $reason, $by) {
            $before = $this->snapStop($stop);
            $stop->mistaken = true;
            $stop->save();
            $this->audit($stop->trip, 'stop', $stop->id, 'mistaken', $before, $this->snapStop($stop), $reason, $by);

            return $stop;
        });
    }

    // ---------------------------------------------- Dashboard: the sessions

    /**
     * A session the specialist types in (he forgot to clock, or clocked on
     * paper).
     *
     * @param  array{clock_in: string, clock_out?: string|null, using_car?: bool, odometer_in?: int|null, odometer_out?: int|null}  $data
     */
    public function addSession(Trip $trip, array $data, string $reason, User $by): TripSession
    {
        if ($trip->mistaken) {
            $this->refuse('status', 'This trip was marked as a mistake.');
        }

        $in = $this->instant($data['clock_in']);
        $out = isset($data['clock_out']) ? $this->instant($data['clock_out']) : null;

        return $this->guardOneOpen(fn () => DB::transaction(function () use ($trip, $data, $in, $out, $reason, $by) {
            Technician::withTrashed()->whereKey($trip->technician_id)->lockForUpdate()->first();

            $this->assertTimes($trip->technician_id, null, $in, $out);

            $session = TripSession::query()->create([
                'trip_id' => $trip->id,
                'technician_id' => $trip->technician_id,
                'clock_in' => $in,
                'clock_out' => $out,
                'using_car' => (bool) ($data['using_car'] ?? false),
                'odometer_in' => $data['odometer_in'] ?? null,
                'odometer_out' => $data['odometer_out'] ?? null,
                'source' => 'specialist',
                'created_by' => $by->id,
            ]);

            $this->audit($trip, 'session', $session->id, 'created', null, $this->snapSession($session), $reason, $by);

            return $session;
        }));
    }

    /**
     * @param  array{clock_in?: string, clock_out?: string|null, odometer_in?: int|null, odometer_out?: int|null, using_car?: bool}  $data
     */
    public function updateSession(TripSession $session, array $data, string $reason, User $by): TripSession
    {
        $this->assertEditable($session);

        $in = array_key_exists('clock_in', $data) ? $this->instant($data['clock_in']) : Carbon::instance($session->clock_in);
        $out = array_key_exists('clock_out', $data)
            ? ($data['clock_out'] === null ? null : $this->instant($data['clock_out']))
            : ($session->clock_out ? Carbon::instance($session->clock_out) : null);

        return $this->guardOneOpen(fn () => DB::transaction(function () use ($session, $data, $in, $out, $reason, $by) {
            Technician::withTrashed()->whereKey($session->technician_id)->lockForUpdate()->first();

            $this->assertTimes($session->technician_id, $session->id, $in, $out);

            $before = $this->snapSession($session);
            $session->clock_in = $in;
            $session->clock_out = $out;
            foreach (['odometer_in', 'odometer_out', 'using_car'] as $key) {
                if (array_key_exists($key, $data)) {
                    $session->{$key} = $data[$key];
                }
            }

            if ($session->isDirty()) {
                $session->save();
                $this->audit($session->trip, 'session', $session->id, 'updated', $before, $this->snapSession($session), $reason, $by);
            }

            return $session;
        }));
    }

    public function markSessionMistaken(TripSession $session, string $reason, User $by): TripSession
    {
        if ($session->mistaken) {
            $this->refuse('status', 'This session is already marked as a mistake.');
        }
        $this->assertEditable($session);

        return DB::transaction(function () use ($session, $reason, $by) {
            $before = $this->snapSession($session);
            $session->mistaken = true;
            $session->save();
            $this->audit($session->trip, 'session', $session->id, 'mistaken', $before, $this->snapSession($session), $reason, $by);

            return $session;
        });
    }

    // ------------------------------------------------ Dashboard: the events

    public function addEventAt(TripSession $session, TripEventKind $kind, string $at, string $reason, User $by): TripEvent
    {
        $this->assertEditable($session);
        $when = $this->instant($at);
        $this->assertInsideSession($session, $when);

        return DB::transaction(function () use ($session, $kind, $when, $reason, $by) {
            $event = $session->events()->create(['kind' => $kind, 'at' => $when, 'created_by' => $by->id]);
            $this->audit($session->trip, 'event', $event->id, 'created', null, $this->snapEvent($event), $reason, $by);

            return $event;
        });
    }

    public function updateEvent(TripEvent $event, string $at, string $reason, User $by): TripEvent
    {
        $session = $event->session;
        $this->assertEditable($session);
        $when = $this->instant($at);
        $this->assertInsideSession($session, $when);

        return DB::transaction(function () use ($event, $session, $when, $reason, $by) {
            $before = $this->snapEvent($event);
            $event->at = $when;
            $event->save();
            $this->audit($session->trip, 'event', $event->id, 'updated', $before, $this->snapEvent($event), $reason, $by);

            return $event;
        });
    }

    public function markEventMistaken(TripEvent $event, string $reason, User $by): TripEvent
    {
        if ($event->mistaken) {
            $this->refuse('status', 'This entry is already marked as a mistake.');
        }
        $session = $event->session;
        $this->assertEditable($session);

        return DB::transaction(function () use ($event, $session, $reason, $by) {
            $before = $this->snapEvent($event);
            $event->mistaken = true;
            $event->save();
            $this->audit($session->trip, 'event', $event->id, 'mistaken', $before, $this->snapEvent($event), $reason, $by);

            return $event;
        });
    }

    // ------------------------------------------- Technician: the clock

    /**
     * Clock in on this trip. One open session per technician, checked three
     * times: under a lock on his row, by looking, and by the unique
     * open_marker index should two requests still race past both.
     */
    public function clockIn(Trip $trip, bool $usingCar, ?int $odometer, ?UploadedFile $photo): TripSession
    {
        return $this->guardOneOpen(fn () => DB::transaction(function () use ($trip, $usingCar, $odometer, $photo) {
            Technician::withTrashed()->whereKey($trip->technician_id)->lockForUpdate()->first();

            $this->assertNotClockedIn($trip->technician_id);

            $session = TripSession::query()->create([
                'trip_id' => $trip->id,
                'technician_id' => $trip->technician_id,
                'clock_in' => now(),
                'using_car' => $usingCar,
                'odometer_in' => $odometer,
                'source' => 'technician',
            ]);

            if ($photo !== null) {
                $this->attachments()->store($session, [$photo]);
            }

            return $session;
        }));
    }

    /**
     * Clock out of this trip's open session. Whatever is still running (a
     * break, a drive) is ended at the same moment first.
     */
    public function clockOut(Trip $trip, ?int $odometer, ?UploadedFile $photo): TripSession
    {
        return DB::transaction(function () use ($trip, $odometer, $photo) {
            $session = $this->openSession($trip);
            if ($session === null) {
                $this->refuse('status', 'You are not clocked in on this trip.');
            }

            TripSession::query()->whereKey($session->id)->lockForUpdate()->first();
            $now = now();

            foreach (array_keys($this->openSpans($session)) as $bucket) {
                $session->events()->create(['kind' => TripEventKind::startOf($bucket)->counterpart(), 'at' => $now]);
            }

            if ($odometer !== null && $session->odometer_in !== null && $odometer < $session->odometer_in) {
                $this->refuse('odometer', "The odometer reads less than when you clocked in ({$session->odometer_in}).");
            }

            $session->clock_out = $now;
            $session->odometer_out = $odometer;
            $session->save();

            if ($photo !== null) {
                $this->attachments()->store($session, [$photo]);
            }

            return $session;
        });
    }

    /**
     * A break / drive / parts run starting or ending now. Only on the clock,
     * one span at a time, and an end only for what is running.
     */
    public function recordEvent(Trip $trip, TripEventKind $kind): TripEvent
    {
        return DB::transaction(function () use ($trip, $kind) {
            $session = $this->openSession($trip);
            if ($session === null) {
                $this->refuse('kind', 'Clock in first.');
            }

            TripSession::query()->whereKey($session->id)->lockForUpdate()->first();
            $spans = $this->openSpans($session);
            $bucket = $kind->bucket();

            if ($kind->opens()) {
                if (isset($spans[$bucket])) {
                    $this->refuse('kind', 'You already started '.self::BUCKET_WORDS[$bucket].'.');
                }
                if ($spans !== []) {
                    $running = array_key_first($spans);
                    $this->refuse('kind', 'End '.self::BUCKET_WORDS[$running].' first.');
                }
            } elseif (! isset($spans[$bucket])) {
                $this->refuse('kind', 'You have not started '.self::BUCKET_WORDS[$bucket].'.');
            }

            return $session->events()->create(['kind' => $kind, 'at' => now()]);
        });
    }

    // -------------------------------------------- Technician: the stops

    public function technicianAddStop(Trip $trip, int $storeId): TripStop
    {
        $onTrip = TripStop::query()->where('trip_id', $trip->id)->where('mistaken', false)->where('store_id', $storeId)->first();
        if ($onTrip !== null) {
            return $onTrip;
        }

        if (! $this->availableStores($trip)->contains('id', $storeId)) {
            $this->refuse('store_id', 'This store is not on your list. Call the office to add it.');
        }

        return $this->addStop($trip, ['store_id' => $storeId], null, '');
    }

    public function arrive(TripStop $stop, ?int $odometer, ?UploadedFile $photo): TripStop
    {
        $this->assertStopLive($stop);
        if ($stop->status === 'arrived') {
            $this->refuse('status', 'You already arrived here.');
        }

        return DB::transaction(function () use ($stop, $odometer, $photo) {
            $stop->forceFill([
                'status' => 'arrived',
                'arrived_at' => now(),
                'left_at' => null,
                'arrival_odometer' => $odometer ?? $stop->arrival_odometer,
            ])->save();

            if ($photo !== null) {
                $this->attachments()->store($stop, [$photo]);
            }

            return $stop;
        });
    }

    public function leave(TripStop $stop): TripStop
    {
        $this->assertStopLive($stop);
        if ($stop->status !== 'arrived') {
            $this->refuse('status', 'Mark that you arrived here first.');
        }

        $stop->forceFill(['status' => 'done', 'left_at' => now()])->save();

        return $stop;
    }

    public function stopNotDone(TripStop $stop, string $reason): TripStop
    {
        $this->assertStopLive($stop);

        $stop->forceFill([
            'status' => 'not_done',
            'not_done_reason' => trim($reason),
            'left_at' => $stop->arrived_at !== null && $stop->left_at === null ? now() : $stop->left_at,
        ])->save();

        return $stop;
    }

    /**
     * He did what he was sent to the stop for.
     *
     *  - work: the issue moves to work_done (written to its status history),
     *    work_performed takes his note, and `maintenance.issue.work_done` goes
     *    out through the outbox;
     *  - inspection: the issue's open inspection gets his findings and
     *    estimate and is done (one is recorded if the office never booked
     *    it); the issue's status does not move.
     *
     * Files go on the issue: `files` as sent, `before` / `after` photos named
     * "Before - …" / "After - …" (AttachmentService::BEFORE_PREFIX) so they
     * read back as such -- no column of their own.
     *
     * @param  array{note?: string|null, findings?: string|null, estimated_cost?: numeric-string|float|null}  $data
     * @param  array<int, UploadedFile>  $files
     * @param  array<int, UploadedFile>  $before
     * @param  array<int, UploadedFile>  $after
     */
    public function completeIssue(TripStop $stop, TicketIssue $issue, array $data, array $files, ?Request $request = null, array $before = [], array $after = []): TripStopIssue
    {
        $this->assertStopLive($stop);

        return DB::transaction(function () use ($stop, $issue, $data, $files, $request, $before, $after) {
            $row = $this->stopIssueRow($stop, $issue);

            if ($row->task_status === 'done') {
                $this->refuse('status', 'This job is already marked done.');
            }
            if ($issue->status->isTerminal()) {
                $this->refuse('status', "This issue is already {$issue->status->label()}.");
            }

            $technician = $stop->trip->technician;
            $note = $this->text($data['note'] ?? null);

            if ($row->purpose === 'inspection') {
                $findings = $this->text($data['findings'] ?? null) ?? $note;
                if ($findings === null) {
                    $this->refuse('findings', 'Say what you found.');
                }

                $inspection = IssueInspection::query()
                    ->where('ticket_issue_id', $issue->id)
                    ->where('status', InspectionStatus::Scheduled->value)
                    ->where('mistaken', false)
                    ->orderByRaw('CASE WHEN technician_id = ? THEN 0 ELSE 1 END', [$technician->id])
                    ->orderByDesc('id')
                    ->first()
                    ?? new IssueInspection([
                        'ticket_issue_id' => $issue->id,
                        'technician_id' => $technician->id,
                        'scheduled_date' => now()->toDateString(),
                    ]);

                $inspection->fill([
                    'status' => InspectionStatus::Done,
                    'done_at' => now(),
                    'findings' => $findings,
                    'estimated_cost' => $data['estimated_cost'] ?? $inspection->estimated_cost,
                ])->save();
            } else {
                if ($issue->status !== IssueStatus::WorkDone) {
                    TicketStatusService::changeIssueStatus($issue, IssueStatus::WorkDone, "Marked done by {$technician->name} on the trip link.");
                }
                if ($note !== null) {
                    $issue->work_performed = $note;
                    $issue->save();
                }
            }

            $this->attachments()->store($issue, $files);
            $this->attachments()->store($issue, $before, AttachmentService::BEFORE_PREFIX);
            $this->attachments()->store($issue, $after, AttachmentService::AFTER_PREFIX);

            $row->forceFill(['task_status' => 'done', 'done_at' => now(), 'not_done_reason' => null])->save();

            if ($row->purpose !== 'inspection') {
                $this->publishWorkDone($stop, $issue->refresh(), $request);
            }

            return $row;
        });
    }

    public function issueNotDone(TripStop $stop, TicketIssue $issue, string $reason): TripStopIssue
    {
        $this->assertStopLive($stop);

        $row = $this->stopIssueRow($stop, $issue);
        if ($row->task_status === 'done') {
            $this->refuse('status', 'This job is already marked done.');
        }

        $row->forceFill(['task_status' => 'not_done', 'done_at' => null, 'not_done_reason' => trim($reason)])->save();

        return $row;
    }

    /**
     * The trip_stop_issues row for the issue on that stop (404 when the issue
     * is not on it).
     */
    public function stopIssueRow(TripStop $stop, TicketIssue $issue): TripStopIssue
    {
        return TripStopIssue::query()
            ->where('trip_stop_id', $stop->id)
            ->where('ticket_issue_id', $issue->id)
            ->lockForUpdate()
            ->firstOrFail();
    }

    // ------------------------------------------- Taking an issue off trips

    /**
     * Take one pending task off its trip (a booking moved to another day, an
     * issue closed or cancelled). A stop left with nothing on it that nobody
     * has been to (no arrival, no purchases, no notes or files) goes too, so
     * the technician's page does not send him to a store with nothing to do;
     * and a trip left with no stops that was never used goes with it
     * (dropIfUnused).
     */
    public function removePendingTask(TripStopIssue $row): void
    {
        if ($row->task_status !== 'pending') {
            return;
        }

        $stop = $row->stop;
        $row->delete();

        if ($stop instanceof TripStop
            && $stop->status === 'planned'
            && $stop->arrived_at === null
            && ! $stop->stopIssues()->exists()
            && ! $stop->purchases()->exists()
            && ! $stop->notes()->exists()
            && ! $stop->attachments()->exists()) {
            $stop->delete();
        }

        if ($stop instanceof TripStop && ($trip = Trip::query()->find($stop->trip_id)) !== null) {
            $this->dropIfUnused($trip);
        }
    }

    /**
     * Remove a trip that has nothing left on it and was never used: no stops
     * (mistaken ones included), no session ever clocked (mistaken ones
     * included), the link never opened, no purchases, notes or files, and not
     * marked mistaken. Anything else is history and stays. Bookings that still
     * name it lose the link to it. Returns whether it was removed.
     *
     * Removing rather than closing: a closed empty trip would only clutter
     * the History list, and the link was never opened, so nobody is following
     * it. If the technician opens it later he gets "not found"; the office
     * sends the link of the trip the work is on now.
     */
    public function dropIfUnused(Trip $trip): bool
    {
        if ($trip->mistaken
            || $trip->last_used_at !== null
            || TripStop::query()->where('trip_id', $trip->id)->exists()
            || TripSession::query()->where('trip_id', $trip->id)->exists()
            || $trip->purchases()->exists()
            || $trip->notes()->exists()
            || $trip->attachments()->exists()) {
            return false;
        }

        $trip->assignments()->update(['trip_id' => null]);
        $trip->delete();

        return true;
    }

    /**
     * An issue was closed or cancelled: what was still planned for it stops.
     *
     *   - its PENDING tasks on open trips: on a trip nobody has clocked on, the
     *     task is taken off (removePendingTask, which also clears an empty stop
     *     and an unused trip); on a trip with clocked time it stays, as history
     *     of what that time was for, marked not done "No longer needed", so
     *     the technician sees it. Done / not done tasks are never touched.
     *   - its bookings from today on (not mistaken, not ended) are ended, with
     *     the same reason;
     *   - its inspections still booked are cancelled.
     *
     * Called from TicketStatusService::changeIssueStatus, the one door every
     * status change goes through, when an open issue becomes closed or
     * cancelled.
     */
    public function releaseFinishedIssue(TicketIssue $issue, IssueStatus $to): void
    {
        $reason = $to === IssueStatus::Cancelled
            ? 'No longer needed: the issue was cancelled.'
            : 'No longer needed: the issue was closed.';

        $rows = TripStopIssue::query()
            ->where('ticket_issue_id', $issue->id)
            ->where('task_status', 'pending')
            ->whereHas('stop.trip', fn ($q) => $q->where('status', TripStatus::Open->value)->where('mistaken', false))
            ->with('stop')
            ->get();

        foreach ($rows as $row) {
            $tripId = $row->stop?->trip_id;
            $clocked = $tripId !== null && TripSession::query()->where('trip_id', $tripId)->exists();

            if ($clocked) {
                $row->forceFill(['task_status' => 'not_done', 'done_at' => null, 'not_done_reason' => $reason])->save();
            } else {
                $this->removePendingTask($row);
            }
        }

        $issue->assignments()
            ->where('mistaken', false)
            ->whereNull('ended_at')
            ->whereDate('assigned_date', '>=', now()->toDateString())
            ->get()
            ->each(fn ($assignment) => $assignment->forceFill(['ended_at' => now(), 'ended_reason' => $reason])->save());

        IssueInspection::query()
            ->where('ticket_issue_id', $issue->id)
            ->where('status', InspectionStatus::Scheduled->value)
            ->where('mistaken', false)
            ->update(['status' => InspectionStatus::Cancelled->value, 'updated_at' => now()]);
    }

    // -------------------------------------------------------------- Audits

    /**
     * @param  array<string, mixed>|null  $before
     * @param  array<string, mixed>|null  $after
     */
    public function audit(Trip $trip, string $subjectType, int $subjectId, string $action, ?array $before, ?array $after, ?string $reason, User $by): TripAudit
    {
        return TripAudit::query()->create([
            'trip_id' => $trip->id,
            'subject_type' => $subjectType,
            'subject_id' => $subjectId,
            'action' => $action,
            'before' => $before,
            'after' => $after,
            'reason' => $this->text($reason),
            'created_by' => $by->id,
        ]);
    }

    // ------------------------------------------------------------- Helpers

    /** How each span reads in a message: "End your break first." */
    private const BUCKET_WORDS = [
        'break' => 'your break',
        'travel' => 'driving',
        'parts_run' => 'the parts run',
    ];

    private function refuse(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }

    /**
     * Run a write that may open a session, turning the unique open_marker
     * index's refusal (two clock-ins racing) into the same 422 as the check.
     *
     * @template T
     *
     * @param  callable(): T  $write
     * @return T
     */
    private function guardOneOpen(callable $write): mixed
    {
        try {
            return $write();
        } catch (UniqueConstraintViolationException) {
            $this->refuse('status', 'He is already clocked in on another trip. Clock out there first.');
        }
    }

    private function assertNotClockedIn(int $technicianId, ?int $ignoreSessionId = null): void
    {
        $open = $this->openSessionOf($technicianId);

        if ($open !== null && $open->id !== $ignoreSessionId) {
            $this->refuse('status', "Already clocked in on trip #{$open->trip_id} since {$open->clock_in->format('M j, g:i A')}. Clock out there first.");
        }
    }

    /**
     * A typed-in session must end after it starts, not overlap another of
     * his sessions (that time would be paid twice) and, when left open, he
     * must not be clocked in anywhere else.
     */
    private function assertTimes(int $technicianId, ?int $ignoreSessionId, Carbon $in, ?Carbon $out): void
    {
        if ($out !== null && $out->lessThanOrEqualTo($in)) {
            $this->refuse('clock_out', 'Clock-out must be after clock-in.');
        }
        if ($in->greaterThan(now()->addMinutes(5))) {
            $this->refuse('clock_in', 'Clock-in cannot be in the future.');
        }

        if ($out === null) {
            $this->assertNotClockedIn($technicianId, $ignoreSessionId);
        }

        $overlap = TripSession::query()
            ->where('technician_id', $technicianId)
            ->where('mistaken', false)
            ->when($ignoreSessionId !== null, fn (Builder $q) => $q->whereKeyNot($ignoreSessionId))
            ->when($out !== null, fn (Builder $q) => $q->where('clock_in', '<', $out))
            ->where(fn (Builder $q) => $q->whereNull('clock_out')->orWhere('clock_out', '>', $in))
            ->orderBy('clock_in')
            ->first();

        if ($overlap !== null) {
            $this->refuse('clock_in', 'This overlaps another session of his (trip #'.$overlap->trip_id.', from '.$overlap->clock_in->format('M j, g:i A').').');
        }
    }

    /**
     * A paid session is frozen: its minutes are on a payment already.
     */
    private function assertEditable(TripSession $session): void
    {
        if ($this->sessionPaymentId($session) !== null) {
            $this->refuse('status', 'This session is already paid. Edit or mark that payment as a mistake first.');
        }
        if ($session->mistaken) {
            $this->refuse('status', 'This session is marked as a mistake.');
        }
    }

    private function assertInsideSession(TripSession $session, Carbon $at): void
    {
        $end = $session->clock_out ?? now();

        if ($at->lessThan($session->clock_in) || $at->greaterThan($end)) {
            $this->refuse('at', 'The time must be inside the session ('.$session->clock_in->format('M j, g:i A').' – '.($session->clock_out?->format('M j, g:i A') ?? 'now').').');
        }
    }

    private function assertStopLive(TripStop $stop): void
    {
        if ($stop->mistaken) {
            $this->refuse('status', 'This visit was removed from the trip.');
        }
    }

    /**
     * An instant typed in any offset, stored in the app's time zone (the
     * datetime columns carry no offset of their own).
     */
    private function instant(string $value): Carbon
    {
        return Carbon::parse($value)->setTimezone(config('app.timezone'));
    }

    private function publishWorkDone(TripStop $stop, TicketIssue $issue, ?Request $request): void
    {
        $ticket = $issue->ticket()->withTrashed()->with('store')->first();
        $trip = $stop->trip;

        // The same `issue` block as the workflow's events (IssueEventPublisher),
        // so a consumer routes every maintenance.issue.* the same way.
        $envelope = app(MaintenanceEventFactory::class)->make(self::WORK_DONE_EVENT, [
            'issue' => [
                'id' => $issue->id,
                'ticket_id' => $issue->ticket_id,
                'store_number' => $ticket?->store?->store_number,
                'other_store' => $ticket?->other_store,
                'title' => $issue->displayTitle(),
                'status' => $issue->status?->value,
                'priority' => $issue->priority?->value,
                'assigned_priority' => $issue->assigned_priority?->value,
            ],
            'work_performed' => $issue->work_performed,
            'technician' => ['id' => $trip->technician_id, 'name' => $trip->technician?->name],
            'trip_id' => $trip->id,
            'trip_stop_id' => $stop->id,
            'done_at' => now()->toIso8601String(),
            'via' => 'trip_link',
        ], $request);

        $row = app(MaintenanceOutboxService::class)->record(self::WORK_DONE_EVENT, $envelope);

        PublishOutboxEventJob::dispatch((string) $row->id)->afterCommit();
    }

    private function attachments(): AttachmentService
    {
        return app(AttachmentService::class);
    }

    /** @return array<string, mixed> */
    private function snapTrip(Trip $trip): array
    {
        return [
            'status' => $trip->status?->value,
            'planned_date' => $trip->planned_date?->toDateString(),
            'instructions' => $trip->instructions,
            'link_idle_hours' => $trip->link_idle_hours,
            'closed_at' => $trip->closed_at?->toIso8601String(),
            'mistaken' => (bool) $trip->mistaken,
        ];
    }

    /** @return array<string, mixed> */
    private function snapStop(TripStop $stop): array
    {
        return [
            'store_id' => $stop->store_id,
            'other_store' => $stop->other_store,
            'status' => $stop->status,
            'mistaken' => (bool) $stop->mistaken,
        ];
    }

    /** @return array<string, mixed> */
    private function snapSession(TripSession $session): array
    {
        return [
            'clock_in' => $session->clock_in?->toIso8601String(),
            'clock_out' => $session->clock_out?->toIso8601String(),
            'using_car' => (bool) $session->using_car,
            'odometer_in' => $session->odometer_in,
            'odometer_out' => $session->odometer_out,
            'mistaken' => (bool) $session->mistaken,
        ];
    }

    /** @return array<string, mixed> */
    private function snapEvent(TripEvent $event): array
    {
        return [
            'trip_session_id' => $event->trip_session_id,
            'kind' => $event->kind?->value,
            'at' => $event->at?->toIso8601String(),
            'mistaken' => (bool) $event->mistaken,
        ];
    }

    private function text(?string $value): ?string
    {
        $value = $value === null ? null : trim($value);

        return $value === '' ? null : $value;
    }
}
