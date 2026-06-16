<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreFinalNoteRequest;
use App\Models\Store;
use App\Models\Ticket;
use App\Services\NoteService;
use App\Services\TicketService;

class TicketFinalNoteController extends Controller
{
    public function __construct(
        private NoteService $notes,
        private TicketService $tickets,
    ) {}

    /**
     * Append a typed final note (Final Notes / What we learned) to a ticket.
     * A ticket can have as many as needed; post once per note.
     */
    public function __invoke(StoreFinalNoteRequest $request, Store $store, Ticket $ticket)
    {
        $data = $request->validated();

        $this->notes->store(
            $ticket,
            $data['body'],
            $data['type'],
            (array) $request->file('files', []),
        );

        return ['data' => $this->tickets->presentFresh($ticket)];
    }
}
