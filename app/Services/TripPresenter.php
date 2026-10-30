<?php

namespace App\Services;

use App\Models\Note;
use App\Models\Store;
use App\Models\TicketIssue;
use App\Models\Trip;
use App\Models\TripAudit;
use App\Models\TripEvent;
use App\Models\TripSession;
use App\Models\TripStop;
use App\Models\TripStopIssue;
use Illuminate\Database\Eloquent\Model;

/**
 * How a trip reads (docs/MAINTENANCE-V2.md §3 "Presented trip").
 *
 * Two audiences:
 *  - the dashboard (MOS): everything, private notes included, and the audit
 *    trail on show;
 *  - the technician's link (`public`): never the audits, never a private
 *    note, plus his `state` and the stores he may add a visit to.
 */
class TripPresenter
{
    /**
     * Eager loads for a presented trip.
     *
     * @var list<string>
     */
    public const WITH = [
        'technician',
        'stops.store',
        'stops.attachments.creator',
        'stops.notes.creator',
        'stops.notes.attachments.creator',
        'stops.stopIssues.ticketIssue.issue',
        'sessions.events',
        'sessions.attachments.creator',
        'sessions.paymentLines.payment',
        'notes.creator',
        'notes.locker',
        'notes.attachments.creator',
        'attachments.creator',
    ];

    public function __construct(
        private TripService $trips,
        private NoteService $notes,
        private AttachmentService $attachments,
        private TechnicianPurchaseService $purchases,
    ) {}

    /**
     * @param  bool  $withAudits  show only
     * @return array<string, mixed>
     */
    public function present(Trip $trip, bool $withAudits = false): array
    {
        $trip->loadMissing(self::WITH);
        $trip->load(['purchases' => fn ($q) => $q->with(TechnicianPurchaseService::WITH)->orderBy('bought_at')->orderBy('id')]);

        $open = $trip->sessions->first(fn (TripSession $s) => $s->clock_out === null && ! $s->mistaken);
        $live = $trip->sessions->where('mistaken', false);
        $durations = $live->mapWithKeys(fn (TripSession $s) => [$s->id => $this->trips->durations($s)]);

        $data = [
            'id' => $trip->id,
            'technician' => $trip->technician ? [
                'id' => $trip->technician->id,
                'name' => $trip->technician->name,
                'phone' => $trip->technician->phone,
            ] : null,
            'status' => $trip->status->value,
            'status_label' => $trip->status->label(),
            'planned_date' => $trip->planned_date?->toDateString(),
            'instructions' => $trip->instructions,
            'mistaken' => (bool) $trip->mistaken,
            'closed_at' => $trip->closed_at,
            'link' => [
                'active' => $this->trips->linkActive($trip),
                'expires_at' => $trip->token_hash !== null && $trip->isOpen() && ! $trip->mistaken
                    ? $this->trips->linkExpiresAt($trip)
                    : null,
                'last_used_at' => $trip->last_used_at,
                'idle_hours' => $trip->link_idle_hours,
            ],
            'on_clock' => $open !== null,
            'open_session_id' => $open?->id,
            'totals' => [
                'paid_minutes' => (int) $durations->sum('paid'),
                'break_minutes' => (int) $durations->sum('break'),
                'miles' => (int) $live->sum(fn (TripSession $s) => $this->miles($s) ?? 0),
            ],
            'stops' => $trip->stops->map(fn (TripStop $s) => $this->presentStop($s, false))->values()->all(),
            'sessions' => $trip->sessions->map(fn (TripSession $s) => $this->presentSession($s))->values()->all(),
            'purchases' => $trip->purchases->map(fn ($p) => $this->purchases->present($p))->values()->all(),
            'notes' => $this->presentNotes($trip, false),
            'attachments' => $this->attachments->presentMany($trip),
            'created_at' => $trip->created_at,
        ];

        if ($withAudits) {
            $data['audits'] = $trip->audits()->with('creator')->get()
                ->map(fn (TripAudit $a) => $this->presentAudit($a))->all();
        }

        return $data;
    }

    /**
     * The trip as the technician's link shows it.
     *
     * @return array<string, mixed>
     */
    public function presentPublic(Trip $trip): array
    {
        $trip->loadMissing(self::WITH);
        $data = $this->present($trip);

        $open = $trip->sessions->first(fn (TripSession $s) => $s->clock_out === null && ! $s->mistaken);
        $elsewhere = $open === null ? $this->trips->openSessionOf($trip->technician_id) : null;

        $data['notes'] = $this->presentNotes($trip, true);
        $data['stops'] = $trip->stops->where('mistaken', false)
            ->map(fn (TripStop $s) => $this->presentStop($s, true))->values()->all();
        $data['sessions'] = collect($data['sessions'])->where('mistaken', false)->values()->all();
        $data['purchases'] = collect($data['purchases'])->where('mistaken', false)->values()->all();
        $data['state'] = $this->trips->state($trip, $open);
        $data['clocked_in_elsewhere'] = $elsewhere !== null;
        $data['stores'] = $this->trips->availableStores($trip)
            ->map(fn (Store $s) => ['id' => $s->id, 'store_number' => $s->store_number])->values()->all();

        unset($data['audits']);

        return $this->withoutEmails($data);
    }

    /**
     * @return array<string, mixed>
     */
    public function presentStop(TripStop $stop, bool $public): array
    {
        return [
            'id' => $stop->id,
            'store' => $stop->store ? ['id' => $stop->store->id, 'store_number' => $stop->store->store_number] : null,
            'other_store' => $stop->other_store,
            'status' => $stop->status,
            'arrived_at' => $stop->arrived_at,
            'left_at' => $stop->left_at,
            'arrival_odometer' => $stop->arrival_odometer,
            'not_done_reason' => $stop->not_done_reason,
            'sort_order' => $stop->sort_order,
            'mistaken' => (bool) $stop->mistaken,
            'attachments' => $this->attachments->presentMany($stop),
            'notes' => $this->presentNotes($stop, $public),
            'issues' => $stop->stopIssues
                ->sortBy('id')
                ->map(fn (TripStopIssue $row) => $this->presentStopIssue($row))
                ->values()->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function presentStopIssue(TripStopIssue $row): array
    {
        /** @var TicketIssue|null $issue */
        $issue = $row->ticketIssue;

        return [
            'ticket_issue_id' => $row->ticket_issue_id,
            'ticket_id' => $issue?->ticket_id,
            'title' => $issue?->displayTitle(),
            'description' => $issue?->description,
            'status' => $issue?->status?->value,
            'status_label' => $issue?->status?->label(),
            'priority' => $issue?->assigned_priority?->value ?? $issue?->priority?->value,
            'purpose' => $row->purpose,
            'task_status' => $row->task_status,
            'done_at' => $row->done_at,
            'not_done_reason' => $row->not_done_reason,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function presentSession(TripSession $session): array
    {
        $paymentId = $this->trips->sessionPaymentId($session);

        return [
            'id' => $session->id,
            'trip_id' => $session->trip_id,
            'clock_in' => $session->clock_in,
            'clock_out' => $session->clock_out,
            'using_car' => (bool) $session->using_car,
            'odometer_in' => $session->odometer_in,
            'odometer_out' => $session->odometer_out,
            'miles' => $this->miles($session),
            'source' => $session->source,
            'mistaken' => (bool) $session->mistaken,
            'paid' => $paymentId !== null,
            'payment_id' => $paymentId,
            'durations' => $this->trips->durations($session),
            'events' => ($session->relationLoaded('events') ? $session->events : $session->events()->get())
                ->map(fn (TripEvent $e) => [
                    'id' => $e->id,
                    'kind' => $e->kind->value,
                    'label' => $e->kind->label(),
                    'at' => $e->at,
                    'mistaken' => (bool) $e->mistaken,
                ])->values()->all(),
            'attachments' => $this->attachments->presentMany($session),
        ];
    }

    /**
     * A row of `GET trip-sessions/unpaid` (and the payments side's unpaid
     * work): the session with its durations and the ticket issues of its
     * trip's stops, so the office can see what the time was for.
     *
     * Needs trip.technician, trip.stops.store, trip.stops.stopIssues.ticketIssue.(issue, ticket.store) and events loaded.
     *
     * @return array<string, mixed>
     */
    public function presentUnpaidSession(TripSession $session): array
    {
        $durations = $this->trips->durations($session);
        $trip = $session->trip;

        $issues = $trip->stops->where('mistaken', false)
            ->flatMap(fn (TripStop $stop) => $stop->stopIssues->map(fn (TripStopIssue $row) => [
                'id' => $row->ticket_issue_id,
                'ticket_id' => $row->ticketIssue?->ticket_id,
                'title' => $row->ticketIssue?->displayTitle(),
                'store_number' => $stop->store?->store_number,
                'other_store' => $stop->other_store,
                'purpose' => $row->purpose,
            ]))
            ->unique('id')->values()->all();

        return [
            'id' => $session->id,
            'trip_id' => $session->trip_id,
            'technician' => $trip->technician ? ['id' => $trip->technician->id, 'name' => $trip->technician->name] : null,
            'clock_in' => $session->clock_in,
            'clock_out' => $session->clock_out,
            'using_car' => (bool) $session->using_car,
            'odometer_in' => $session->odometer_in,
            'odometer_out' => $session->odometer_out,
            'miles' => $this->miles($session),
            'source' => $session->source,
            'durations' => $durations,
            'paid_minutes' => $durations['paid'],
            'ticket_issues' => $issues,
        ];
    }

    /**
     * Eager loads presentUnpaidSession() needs.
     *
     * @var list<string>
     */
    public const UNPAID_WITH = [
        'events',
        'trip.technician',
        'trip.stops.store',
        'trip.stops.stopIssues.ticketIssue.issue',
    ];

    /**
     * @return array<string, mixed>
     */
    public function presentAudit(TripAudit $audit): array
    {
        return [
            'id' => $audit->id,
            'subject_type' => $audit->subject_type,
            'subject_id' => $audit->subject_id,
            'action' => $audit->action,
            'before' => $audit->before,
            'after' => $audit->after,
            'reason' => $audit->reason,
            'user' => $audit->creator ? ['id' => $audit->creator->id, 'name' => $audit->creator->name] : null,
            'created_at' => $audit->created_at,
        ];
    }

    /**
     * The link is opened by someone outside the company: who wrote a note or
     * uploaded a file is shown by name only.
     *
     * @param  array<mixed>  $data
     * @return array<mixed>
     */
    private function withoutEmails(array $data): array
    {
        unset($data['email']);

        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $data[$key] = $this->withoutEmails($value);
            }
        }

        return $data;
    }

    private function miles(TripSession $session): ?int
    {
        if ($session->odometer_in === null || $session->odometer_out === null || $session->odometer_out < $session->odometer_in) {
            return null;
        }

        return $session->odometer_out - $session->odometer_in;
    }

    /**
     * Notes on a trip or stop. The dashboard sees every note, private ones
     * flagged; the link never sees a private one.
     *
     * @return array<int, array<string, mixed>>|null
     */
    private function presentNotes(Model $owner, bool $public): ?array
    {
        if (! $owner->relationLoaded('notes')) {
            return null;
        }

        return $owner->notes
            ->when($public, fn ($notes) => $notes->reject(fn (Note $n) => (bool) $n->is_private))
            ->sortBy('id')
            ->map(fn (Note $n) => $this->notes->present($n))
            ->values()->all();
    }
}
