<?php

namespace App\Http\Controllers;

use App\Enums\TripEventKind;
use App\Http\Requests\TripEventStoreRequest;
use App\Http\Requests\TripEventUpdateRequest;
use App\Http\Requests\TripReasonRequest;
use App\Http\Requests\TripSessionStoreRequest;
use App\Http\Requests\TripSessionUpdateRequest;
use App\Http\Requests\TripUnpaidSessionsRequest;
use App\Models\Trip;
use App\Models\TripEvent;
use App\Models\TripSession;
use App\Models\User;
use App\Services\TripPresenter;
use App\Services\TripService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;

/**
 * The specialist's corrections to a trip's clocking (docs/MAINTENANCE-V2.md
 * §3): sessions typed in or fixed, events typed in, moved or marked as a
 * mistake -- each with a reason, each written to trip_audits -- and the list
 * of sessions nobody has paid yet. A paid session is frozen.
 *
 * Routes are scope-bound: {session} must be the trip's, {event} the
 * session's.
 */
class TripSessionController extends Controller
{
    public function __construct(private TripService $trips, private TripPresenter $presenter) {}

    public function store(TripSessionStoreRequest $request, Trip $trip): JsonResponse
    {
        $data = $request->validated();
        $reason = $data['reason'];
        unset($data['reason']);

        $this->trips->addSession($trip, $data, $reason, $this->user());

        return $this->respond($trip, 201);
    }

    public function update(TripSessionUpdateRequest $request, Trip $trip, TripSession $session): JsonResponse
    {
        $data = $request->validated();
        $reason = $data['reason'];
        unset($data['reason']);

        $this->trips->updateSession($session, $data, $reason, $this->user());

        return $this->respond($trip);
    }

    public function mistaken(TripReasonRequest $request, Trip $trip, TripSession $session): JsonResponse
    {
        $this->trips->markSessionMistaken($session, $request->validated('reason'), $this->user());

        return $this->respond($trip);
    }

    public function eventsStore(TripEventStoreRequest $request, Trip $trip, TripSession $session): JsonResponse
    {
        $data = $request->validated();

        $this->trips->addEventAt($session, TripEventKind::from($data['kind']), $data['at'], $data['reason'], $this->user());

        return $this->respond($trip, 201);
    }

    public function eventsUpdate(TripEventUpdateRequest $request, Trip $trip, TripSession $session, TripEvent $event): JsonResponse
    {
        $data = $request->validated();

        $this->trips->updateEvent($event, $data['at'], $data['reason'], $this->user());

        return $this->respond($trip);
    }

    public function eventsMistaken(TripReasonRequest $request, Trip $trip, TripSession $session, TripEvent $event): JsonResponse
    {
        $this->trips->markEventMistaken($event, $request->validated('reason'), $this->user());

        return $this->respond($trip);
    }

    /**
     * `GET trip-sessions/unpaid`: closed sessions no live payment claims,
     * oldest first, with their durations and the ticket issues of their
     * trip's stops.
     */
    public function unpaid(TripUnpaidSessionsRequest $request): JsonResponse
    {
        $technicianId = $request->validated('technician_id');

        $sessions = $this->trips->unpaidSessionsQuery()
            ->with(TripPresenter::UNPAID_WITH)
            ->when($technicianId, fn (Builder $q, $id) => $q->where('technician_id', (int) $id))
            ->orderBy('clock_in')
            ->orderBy('id')
            ->get();

        return response()->json([
            'data' => $sessions->map(fn (TripSession $s) => $this->presenter->presentUnpaidSession($s))->values()->all(),
        ]);
    }

    private function respond(Trip $trip, int $status = 200): JsonResponse
    {
        return response()->json(['data' => $this->presenter->present($trip->refresh()->load(TripPresenter::WITH), true)], $status);
    }

    private function user(): User
    {
        /** @var User $user */
        $user = Auth::user();

        return $user;
    }
}
