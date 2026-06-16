<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreTicketRequest;
use App\Models\Store;
use App\Models\Ticket;
use App\Services\TicketService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class TicketController extends Controller
{
    public function __construct(private TicketService $tickets) {}

    public function index(Request $request, Store $store)
    {
        return $this->tickets->index($request, $store);
    }

    public function globalIndex(Request $request)
    {
        return $this->tickets->index($request);
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
