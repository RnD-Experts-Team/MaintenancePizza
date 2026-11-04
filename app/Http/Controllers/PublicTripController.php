<?php

namespace App\Http\Controllers;

use App\Enums\TripEventKind;
use App\Http\Requests\PublicTripClockInRequest;
use App\Http\Requests\PublicTripEventRequest;
use App\Http\Requests\PublicTripIssueDoneRequest;
use App\Http\Requests\PublicTripLookupRequest;
use App\Http\Requests\PublicTripNoteRequest;
use App\Http\Requests\PublicTripOdometerRequest;
use App\Http\Requests\PublicTripPurchaseRequest;
use App\Http\Requests\PublicTripReasonRequest;
use App\Http\Requests\PublicTripStopRequest;
use App\Models\Part;
use App\Models\Supplier;
use App\Models\TicketIssue;
use App\Models\Trip;
use App\Models\TripStop;
use App\Services\NoteService;
use App\Services\TechnicianPurchaseService;
use App\Services\TripPresenter;
use App\Services\TripService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * The technician's trip link (docs/MAINTENANCE.md §3 "Public endpoints").
 *
 * No dashboard login: the `trip.token` middleware has already opened the
 * trip from the token (404 / 410 otherwise), stamped the link as used and
 * taken {token} off the route. Every action returns the trip as his page
 * shows it -- never the audits, never a private note.
 *
 * {stop} and {ticketIssue} are looked up under the trip here, not by route
 * binding, so a stop of another trip is a 404 whatever its id.
 */
class PublicTripController extends Controller
{
    public function __construct(
        private TripService $trips,
        private TripPresenter $presenter,
        private TechnicianPurchaseService $purchases,
        private NoteService $notes,
    ) {}

    public function show(Request $request): JsonResponse
    {
        return $this->respond($request);
    }

    public function clockIn(PublicTripClockInRequest $request): JsonResponse
    {
        $this->trips->clockIn(
            $this->trip($request),
            $request->boolean('using_car'),
            $request->filled('odometer') ? $request->integer('odometer') : null,
            $request->file('photo'),
        );

        return $this->respond($request, 201);
    }

    public function clockOut(PublicTripOdometerRequest $request): JsonResponse
    {
        $this->trips->clockOut(
            $this->trip($request),
            $request->filled('odometer') ? $request->integer('odometer') : null,
            $request->file('photo'),
        );

        return $this->respond($request);
    }

    public function events(PublicTripEventRequest $request): JsonResponse
    {
        $this->trips->recordEvent($this->trip($request), TripEventKind::from($request->validated('kind')));

        return $this->respond($request, 201);
    }

    public function stopsStore(PublicTripStopRequest $request): JsonResponse
    {
        $this->trips->technicianAddStop($this->trip($request), $request->integer('store_id'));

        return $this->respond($request, 201);
    }

    public function arrive(PublicTripOdometerRequest $request, string $stop): JsonResponse
    {
        $this->trips->arrive(
            $this->stop($request, $stop),
            $request->filled('odometer') ? $request->integer('odometer') : null,
            $request->file('photo'),
        );

        return $this->respond($request);
    }

    public function leave(Request $request, string $stop): JsonResponse
    {
        $this->trips->leave($this->stop($request, $stop));

        return $this->respond($request);
    }

    public function stopNotDone(PublicTripReasonRequest $request, string $stop): JsonResponse
    {
        $this->trips->stopNotDone($this->stop($request, $stop), $request->validated('reason'));

        return $this->respond($request);
    }

    public function issueDone(PublicTripIssueDoneRequest $request, string $stop, string $ticketIssue): JsonResponse
    {
        $stopModel = $this->stop($request, $stop);

        $this->trips->completeIssue(
            $stopModel,
            $this->issue($stopModel, $ticketIssue),
            $request->validated(),
            (array) $request->file('files', []),
            $request,
            (array) $request->file('before', []),
            (array) $request->file('after', []),
        );

        return $this->respond($request);
    }

    public function issueNotDone(PublicTripReasonRequest $request, string $stop, string $ticketIssue): JsonResponse
    {
        $stopModel = $this->stop($request, $stop);

        $this->trips->issueNotDone($stopModel, $this->issue($stopModel, $ticketIssue), $request->validated('reason'));

        return $this->respond($request);
    }

    public function purchasesStore(PublicTripPurchaseRequest $request): JsonResponse
    {
        $trip = $this->trip($request);
        $data = $request->validated();
        $issueId = isset($data['ticket_issue_id']) ? (int) $data['ticket_issue_id'] : null;

        if ($issueId !== null && ! $this->purchases->issueOnTrip($trip, $issueId)) {
            throw ValidationException::withMessages(['ticket_issue_id' => 'That job is not on this trip.']);
        }

        $this->purchases->create($data + [
            'technician_id' => $trip->technician_id,
            'trip_id' => $trip->id,
            'trip_stop_id' => $this->purchases->stopIdFor($trip, $issueId),
        ], (array) $request->file('receipt', []), null);

        return $this->respond($request, 201);
    }

    /**
     * A note from the technician: on the trip, or on one of its visits.
     * Always visible to everyone (never private).
     */
    public function notesStore(PublicTripNoteRequest $request): JsonResponse
    {
        $trip = $this->trip($request);
        $owner = $trip;

        if ($request->filled('stop_id')) {
            $owner = $trip->stops()->where('mistaken', false)->whereKey($request->integer('stop_id'))->first()
                ?? throw ValidationException::withMessages(['stop_id' => 'That visit is not on this trip.']);
        }

        $this->notes->store($owner, $request->validated('body'), null, (array) $request->file('files', []), false);

        return $this->respond($request, 201);
    }

    public function suppliers(PublicTripLookupRequest $request): JsonResponse
    {
        return $this->lookup(Supplier::query(), $request->validated('search'));
    }

    public function parts(PublicTripLookupRequest $request): JsonResponse
    {
        return $this->lookup(Part::query(), $request->validated('search'));
    }

    // ------------------------------------------------------------- Helpers

    /**
     * Name lookups for the purchase form: id and name only, 20 at most.
     *
     * @param  Builder<Supplier>|Builder<Part>  $query
     */
    private function lookup(Builder $query, ?string $search): JsonResponse
    {
        $search = trim((string) $search);

        $rows = $query
            ->when($search !== '', fn (Builder $q) => $q->where('name', 'like', '%'.addcslashes($search, '%_\\').'%'))
            ->orderBy('name')
            ->limit(20)
            ->get(['id', 'name']);

        return response()->json(['data' => $rows->map(fn ($r) => ['id' => $r->id, 'name' => $r->name])->all()]);
    }

    private function trip(Request $request): Trip
    {
        /** @var Trip $trip */
        $trip = $request->attributes->get('trip');

        return $trip;
    }

    private function stop(Request $request, string $id): TripStop
    {
        return $this->trip($request)->stops()->whereKey((int) $id)->firstOrFail();
    }

    private function issue(TripStop $stop, string $id): TicketIssue
    {
        return TicketIssue::query()
            ->whereKey((int) $id)
            ->whereHas('tripStopIssues', fn (Builder $q) => $q->where('trip_stop_id', $stop->id))
            ->firstOrFail();
    }

    private function respond(Request $request, int $status = 200): JsonResponse
    {
        $trip = $this->trip($request)->refresh()->load(TripPresenter::WITH);

        return response()->json(['data' => $this->presenter->presentPublic($trip)], $status);
    }
}
