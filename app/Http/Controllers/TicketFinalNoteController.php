<?php

namespace App\Http\Controllers;

use App\Http\Requests\SetFinalNoteRequest;
use App\Models\Store;
use App\Models\Ticket;
use App\Services\TicketService;

class TicketFinalNoteController extends Controller
{
    public function __construct(private TicketService $tickets) {}

    /**
     * Set (or clear) a ticket's optional final note.
     */
    public function __invoke(SetFinalNoteRequest $request, Store $store, Ticket $ticket)
    {
        return ['data' => $this->tickets->setFinalNote($ticket, $request->validated()['final_note'])];
    }
}
