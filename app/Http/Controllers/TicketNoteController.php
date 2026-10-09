<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateNotePrivacyRequest;
use App\Models\Note;
use App\Models\Store;
use App\Models\Ticket;
use App\Services\NoteService;

/**
 * A ticket's notes, read in one go, and locking them.
 *
 *   GET   /stores/{store}/tickets/{ticket}/notes                  the normal notes
 *   GET   /stores/{store}/tickets/{ticket}/notes/all              normal + private (MOS)
 *   PATCH /stores/{store}/tickets/{ticket}/notes/{note}/privacy   lock / unlock
 *
 * Every note on the ticket -- its own, its issues', its records' -- each with
 * the `owner` it sits on. Which list a person gets is decided by the auth
 * rules: the screen asks for /all when the rules allow it.
 */
class TicketNoteController extends Controller
{
    public function __construct(private NoteService $notes) {}

    public function index(Store $store, Ticket $ticket)
    {
        return ['data' => $this->present($ticket, false)];
    }

    public function all(Store $store, Ticket $ticket)
    {
        return ['data' => $this->present($ticket, true)];
    }

    public function privacy(UpdateNotePrivacyRequest $request, Store $store, Ticket $ticket, Note $note)
    {
        $note = $this->notes->setPrivacy($note, $request->boolean('is_private'));

        return ['data' => $this->notes->present($note)];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function present(Ticket $ticket, bool $withPrivate): array
    {
        return $this->notes->ticketNotes($ticket, $withPrivate)
            ->map(fn (Note $note) => $this->notes->presentOnTicket($note))
            ->all();
    }
}
