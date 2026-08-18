<?php

namespace App\Http\Controllers;

use App\Http\Requests\CreateOtherTicketRequest;
use App\Http\Requests\StoreTicketRequest;
use App\Models\Store;
use App\Models\Ticket;
use App\Services\TicketAnalyticsService;
use App\Services\TicketService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class TicketController extends Controller
{
    public function __construct(
        private TicketService $tickets,
        private TicketAnalyticsService $analytics,
    ) {}

    public function index(Request $request, Store $store)
    {
        $paginator = $this->tickets->index($request, $store);

        return $request->boolean('include_analytics')
            ? array_merge($paginator->toArray(), ['analytics' => $this->analytics->summarize($request, $store)])
            : $paginator;
    }

    public function globalIndex(Request $request)
    {
        $paginator = $this->tickets->index($request);

        return $request->boolean('include_analytics')
            ? array_merge($paginator->toArray(), ['analytics' => $this->analytics->summarize($request)])
            : $paginator;
    }

    public function analytics(Request $request, Store $store): array
    {
        return ['data' => $this->analytics->summarize($request, $store)];
    }

    public function globalAnalytics(Request $request): array
    {
        return ['data' => $this->analytics->summarize($request)];
    }

    public function storeOther(CreateOtherTicketRequest $request): JsonResponse
    {
        $ticketFiles = (array) $request->file('files', []);

        $issueFiles = [];
        foreach ((array) $request->file('issues', []) as $i => $issueFileData) {
            if (is_array($issueFileData) && ! empty($issueFileData['files'])) {
                $issueFiles[(int) $i] = (array) $issueFileData['files'];
            }
        }

        return response()->json([
            'data' => $this->tickets->createOther($request->validated(), $ticketFiles, $issueFiles),
        ], 201);
    }

    public function store(StoreTicketRequest $request, Store $store)
    {
        // Ticket-level files (direct attachments to the ticket).
        $ticketFiles = (array) $request->file('files', []);

        // Per-issue files keyed by issue array index (issues[0][files][], etc.).
        $issueFiles = [];
        foreach ((array) $request->file('issues', []) as $i => $issueFileData) {
            if (is_array($issueFileData) && ! empty($issueFileData['files'])) {
                $issueFiles[(int) $i] = (array) $issueFileData['files'];
            }
        }

        return response()->json([
            'data' => $this->tickets->create($store, $request->validated(), $ticketFiles, $issueFiles),
        ], 201);
    }

    public function destroy(Store $store, Ticket $ticket): Response
    {
        $this->tickets->delete($ticket);

        return response()->noContent();
    }

    public function restore(Store $store, Ticket $ticket)
    {
        return ['data' => $this->tickets->restore($ticket)];
    }
}
