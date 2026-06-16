<?php

namespace App\Services;

use App\Enums\FinalNoteType;
use App\Models\Note;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Creates and presents the polymorphic free-text notes that any entity can
 * carry. A note may itself have file attachments, which are persisted through
 * AttachmentService (the same pipeline the workflow records use).
 */
class NoteService
{
    public function __construct(private AttachmentService $attachments) {}

    /**
     * Attach a note (and any files) to an owning model exposing a `notes()`
     * morphMany relation.
     *
     * @param  array<int, UploadedFile>  $files
     */
    public function store(Model $owner, string $body, ?string $type, array $files): Note
    {
        $note = DB::transaction(function () use ($owner, $body, $type, $files) {
            $note = $owner->notes()->make(['body' => $body, 'type' => $type]);
            $note->created_by = Auth::id();
            $note->save();

            $this->attachments->store($note, $files);

            return $note;
        });

        return $note->load(['attachments.creator', 'creator']);
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
     * Present a model's loaded `notes` relation, or null when not loaded.
     *
     * @return array<int, array<string, mixed>>|null
     */
    public function presentMany(Model $owner): ?array
    {
        if (! $owner->relationLoaded('notes')) {
            return null;
        }

        return $owner->notes->map(fn (Note $n) => $this->present($n))->all();
    }
}
