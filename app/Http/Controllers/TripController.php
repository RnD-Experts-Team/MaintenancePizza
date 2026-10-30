<?php

namespace App\Http\Controllers;

use App\Http\Requests\TripActionRequest;
use App\Http\Requests\TripAttachmentRequest;
use App\Http\Requests\TripIndexRequest;
use App\Http\Requests\TripNoteRequest;
use App\Http\Requests\TripReasonRequest;
use App\Http\Requests\TripStopStoreRequest;
use App\Http\Requests\TripStoreRequest;
use App\Http\Requests\TripUpdateRequest;
use App\Models\Trip;
use App\Models\TripStop;
use App\Models\User;
use App\Services\AttachmentService;
use App\Services\NoteService;
use App\Services\TripPresenter;
use App\Services\TripService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

/**
 * Trips from the dashboard (docs/MAINTENANCE-V2.md §3 "Dashboard endpoints"):
 * the list, a trip by hand, its link, closing and reopening, its stops, and
 * its notes and files. Every change is written to trip_audits by TripService.
 */
class TripController extends Controller
{
    public function __construct(
        private TripService $trips,
        private TripPresenter $presenter,
        private NoteService $notes,
        private AttachmentService $attachments,
    ) {}

    public function index(TripIndexRequest $request): JsonResponse
    {
        $data = $request->validated();

        $query = Trip::query()
            ->with(TripPresenter::WITH)
            ->when($data['status'] ?? null, fn (Builder $q, string $s) => $q->where('status', $s))
            ->when($data['technician_id'] ?? null, fn (Builder $q, $id) => $q->where('technician_id', (int) $id))
            ->when($data['from'] ?? null, fn (Builder $q, string $d) => $q->whereDate('planned_date', '>=', $d))
            ->when($data['to'] ?? null, fn (Builder $q, string $d) => $q->whereDate('planned_date', '<=', $d))
            ->when($request->boolean('on_clock'), fn (Builder $q) => $q->whereHas('sessions', fn (Builder $s) => $s
                ->whereNull('clock_out')->where('mistaken', false)))
            ->when($data['search'] ?? null, function (Builder $q, string $search) {
                $like = '%'.addcslashes(trim($search), '%_\\').'%';

                $q->where(fn (Builder $w) => $w
                    ->when(ctype_digit(trim($search)), fn (Builder $id) => $id->orWhere('trips.id', (int) trim($search)))
                    ->orWhere('instructions', 'like', $like)
                    ->orWhereHas('technician', fn (Builder $t) => $t->where('name', 'like', $like))
                    ->orWhereHas('stops', fn (Builder $s) => $s
                        ->where('other_store', 'like', $like)
                        ->orWhereHas('store', fn (Builder $st) => $st->where('store_number', 'like', $like)))
                    ->orWhereHas('stopIssues.ticketIssue', fn (Builder $i) => $i
                        ->where('other_title', 'like', $like)
                        ->orWhereHas('issue', fn (Builder $c) => $c->where('title', 'like', $like))));
            })
            ->orderByRaw("CASE WHEN status = 'open' THEN 0 ELSE 1 END")
            ->orderByDesc('planned_date')
            ->orderByDesc('id');

        $page = $query->paginate((int) ($data['per_page'] ?? 25));

        return response()->json([
            'data' => collect($page->items())->map(fn (Trip $t) => $this->presenter->present($t))->all(),
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'from' => $page->firstItem(),
                'to' => $page->lastItem(),
                'total' => $page->total(),
            ],
        ]);
    }

    /**
     * The trip comes back with its link: `trip_link.url_token` is the only
     * time the plain token is ever shown.
     */
    public function store(TripStoreRequest $request): JsonResponse
    {
        ['trip' => $trip, 'token' => $token] = $this->trips->createTrip($request->validated(), $this->user());

        return response()->json(['data' => $this->presentWithLink($trip, $token)], 201);
    }

    public function show(Trip $trip): JsonResponse
    {
        return $this->respond($trip);
    }

    public function update(TripUpdateRequest $request, Trip $trip): JsonResponse
    {
        $data = $request->validated();
        $reason = $data['reason'] ?? null;
        unset($data['reason']);

        $this->trips->updateTrip($trip, $data, $reason, $this->user());

        return $this->respond($trip);
    }

    public function link(Trip $trip): JsonResponse
    {
        $token = $this->trips->rotateLink($trip, $this->user());

        return response()->json(['data' => $this->presentWithLink($trip, $token)]);
    }

    public function close(TripActionRequest $request, Trip $trip): JsonResponse
    {
        $this->trips->closeTrip($trip, $request->validated('reason'), $this->user());

        return $this->respond($trip);
    }

    public function reopen(TripActionRequest $request, Trip $trip): JsonResponse
    {
        $this->trips->reopenTrip($trip, $request->validated('reason'), $this->user());

        return $this->respond($trip);
    }

    public function mistaken(TripReasonRequest $request, Trip $trip): JsonResponse
    {
        $this->trips->markTripMistaken($trip, $request->validated('reason'), $this->user());

        return $this->respond($trip);
    }

    public function stopsStore(TripStopStoreRequest $request, Trip $trip): JsonResponse
    {
        if ($trip->mistaken) {
            throw ValidationException::withMessages(['status' => 'This trip was marked as a mistake.']);
        }

        $this->trips->addStop($trip, $request->validated(), $this->user());

        return $this->respond($trip, 201);
    }

    public function stopsMistaken(TripReasonRequest $request, Trip $trip, TripStop $stop): JsonResponse
    {
        $this->trips->markStopMistaken($stop, $request->validated('reason'), $this->user());

        return $this->respond($trip);
    }

    public function notesStore(TripNoteRequest $request, Trip $trip): JsonResponse
    {
        $data = $request->validated();

        $note = $this->notes->store($trip, $data['body'], null, (array) $request->file('files', []), $request->boolean('is_private'));

        return response()->json(['data' => $this->notes->present($note)], 201);
    }

    public function attachmentsStore(TripAttachmentRequest $request, Trip $trip): JsonResponse
    {
        $created = $this->attachments->store($trip, (array) $request->file('files', []));

        return response()->json([
            'data' => array_map(fn ($a) => $this->attachments->present($a->load('creator')), $created),
        ], 201);
    }

    private function respond(Trip $trip, int $status = 200): JsonResponse
    {
        return response()->json(['data' => $this->presenter->present($this->fresh($trip), true)], $status);
    }

    /**
     * @return array<string, mixed>
     */
    private function presentWithLink(Trip $trip, string $token): array
    {
        $trip = $this->fresh($trip);

        return $this->presenter->present($trip, true) + [
            'trip_link' => [
                'url_token' => $token,
                'expires_after_hours' => $trip->link_idle_hours,
            ],
        ];
    }

    private function fresh(Trip $trip): Trip
    {
        return $trip->refresh()->load(TripPresenter::WITH);
    }

    private function user(): User
    {
        /** @var User $user */
        $user = Auth::user();

        return $user;
    }
}
