<?php

namespace App\Services;

use App\Enums\FinalNoteType;
use App\Models\Assignment;
use App\Models\AssignmentDelay;
use App\Models\AttendanceEntry;
use App\Models\Diagnosis;
use App\Models\Note;
use App\Models\PartUsage;
use App\Models\PayEntry;
use App\Models\Ticket;
use App\Models\TicketIssue;
use App\Models\Warranty;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Creates and presents the polymorphic free-text notes that any entity can
 * carry. A note may itself have file attachments, which are persisted through
 * AttachmentService (the same pipeline the workflow records use).
 *
 * A note on a ticket can be private ("locked", MOS only). Private notes never
 * appear in the notes embedded in other reads (presentMany); they come only
 * from the ticket's "all notes" endpoint, which the auth rules give to those
 * allowed to see them. Their files are hidden with them.
 */
class NoteService
{
    /** What can carry a note on a ticket, under the name the API gives it. */
    private const TICKET_OWNERS = [
        Ticket::class => 'ticket',
        TicketIssue::class => 'ticket_issue',
        Assignment::class => 'assignment',
        AssignmentDelay::class => 'assignment_delay',
        Diagnosis::class => 'diagnosis',
        AttendanceEntry::class => 'attendance_entry',
        PartUsage::class => 'part_usage',
        PayEntry::class => 'pay_entry',
        Warranty::class => 'warranty',
    ];

    public function __construct(private AttachmentService $attachments) {}

    /**
     * Attach a note (and any files) to an owning model exposing a `notes()`
     * morphMany relation.
     *
     * @param  array<int, UploadedFile>  $files
     */
    public function store(Model $owner, string $body, ?string $type, array $files, bool $isPrivate = false): Note
    {
        $note = DB::transaction(function () use ($owner, $body, $type, $files, $isPrivate) {
            $note = $owner->notes()->make(['body' => $body, 'type' => $type]);
            $note->created_by = Auth::id();

            if ($isPrivate) {
                $note->is_private = true;
                $note->locked_by = Auth::id();
                $note->locked_at = now();
            }

            $note->save();

            $this->attachments->store($note, $files);

            return $note;
        });

        return $note->load(['attachments.creator', 'creator', 'locker']);
    }

    /**
     * Lock (private, MOS only) or unlock a note. Records who did it, and when.
     */
    public function setPrivacy(Note $note, bool $isPrivate): Note
    {
        $note->is_private = $isPrivate;
        $note->locked_by = Auth::id();
        $note->locked_at = now();
        $note->save();

        return $note->load(['attachments.creator', 'creator', 'locker']);
    }

    /**
     * Every note on a ticket -- on the ticket, its issues, and the records on
     * those issues -- oldest first. Private ones only when asked for.
     *
     * @return Collection<int, Note>
     */
    public function ticketNotes(Ticket $ticket, bool $withPrivate): Collection
    {
        return $this->onTicket($ticket)
            ->when(!$withPrivate, fn (Builder $q) => $q->where('is_private', false))
            ->with(['attachments.creator', 'creator', 'locker'])
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();
    }

    public function isOnTicket(Note $note, Ticket $ticket): bool
    {
        return $this->onTicket($ticket)->whereKey($note->id)->exists();
    }

    /**
     * @return array<string, mixed>
     */
    public function present(Note $note): array
    {
        $type = $note->type ? FinalNoteType::tryFrom($note->type) : null;

        return [
            'id' => $note->id,
            'type' => $note->type,
            // Friendly label when the type is a known FinalNoteType, else null.
            'type_label' => $type?->label(),
            'body' => $note->body,
            'is_private' => (bool) $note->is_private,
            'locked_by' => $note->locked_by,
            'locked_at' => $note->locked_at,
            'locker' => $note->relationLoaded('locker') && $note->locker
                ? ['id' => $note->locker->id, 'name' => $note->locker->name]
                : null,
            'attachments' => $this->attachments->presentMany($note),
            'created_by' => $note->created_by,
            'creator' => $note->relationLoaded('creator') && $note->creator
                ? [
                    'id' => $note->creator->id,
                    'name' => $note->creator->name,
                    'email' => $note->creator->email,
                ]
                : null,
            'created_at' => $note->created_at,
            'updated_at' => $note->updated_at,
        ];
    }

    /**
     * A note from ticketNotes(), with what it is on -- so a screen can put it
     * under the right issue or record.
     *
     * @return array<string, mixed>
     */
    public function presentOnTicket(Note $note): array
    {
        return $this->present($note) + [
            'owner' => [
                'type' => self::TICKET_OWNERS[$note->notable_type] ?? null,
                'id' => $note->notable_id,
            ],
        ];
    }

    /**
     * Present a model's loaded `notes` relation, or null when not loaded.
     * Never the private ones -- see the class comment.
     *
     * @return array<int, array<string, mixed>>|null
     */
    public function presentMany(Model $owner): ?array
    {
        if (! $owner->relationLoaded('notes')) {
            return null;
        }

        return $owner->notes
            ->reject(fn (Note $n) => (bool) $n->is_private)
            ->map(fn (Note $n) => $this->present($n))
            ->values()
            ->all();
    }

    /**
     * Everything on a ticket that can hold notes and files: the ticket, its
     * issues, the records on those issues, and reschedules of its bookings --
     * as [model class => ids]. Records reach a ticket through its issues.
     *
     * @return array<class-string<Model>, array<int, int>>
     */
    public function ticketHolders(Ticket $ticket): array
    {
        $onTicket = fn (Builder $q) => $q->where('ticket_issues.ticket_id', $ticket->id);
        $assignmentIds = Assignment::query()->whereHas('ticketIssues', $onTicket)->pluck('id')->all();

        return [
            Ticket::class => [$ticket->id],
            TicketIssue::class => $ticket->ticketIssues()->pluck('id')->all(),
            Assignment::class => $assignmentIds,
            AssignmentDelay::class => AssignmentDelay::query()->whereIn('assignment_id', $assignmentIds)->pluck('id')->all(),
            Diagnosis::class => Diagnosis::query()->whereHas('ticketIssues', $onTicket)->pluck('id')->all(),
            AttendanceEntry::class => AttendanceEntry::query()->whereHas('ticketIssues', $onTicket)->pluck('id')->all(),
            PartUsage::class => PartUsage::query()->whereHas('ticketIssues', $onTicket)->pluck('id')->all(),
            PayEntry::class => PayEntry::query()->whereHas('ticketIssues', $onTicket)->pluck('id')->all(),
            Warranty::class => Warranty::query()->whereHas('ticketIssues', $onTicket)->pluck('id')->all(),
        ];
    }

    /**
     * @return Builder<Note>
     */
    private function onTicket(Ticket $ticket): Builder
    {
        $holders = $this->ticketHolders($ticket);

        return Note::query()->where(function (Builder $q) use ($holders) {
            foreach ($holders as $class => $ids) {
                if ($ids !== []) {
                    $q->orWhere(fn (Builder $w) => $w->where('notable_type', $class)->whereIn('notable_id', $ids));
                }
            }
        });
    }
}
