<?php

namespace App\Models\Concerns;

use App\Models\Attachment;
use App\Models\Note;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * Gives a model the two polymorphic owner relations: free-text notes and file
 * attachments. Applied to every domain entity so any of them can carry as many
 * notes and attachments as needed.
 */
trait HasNotesAndAttachments
{
    /** @return MorphMany<Note, $this> */
    public function notes(): MorphMany
    {
        return $this->morphMany(Note::class, 'notable');
    }

    /** @return MorphMany<Attachment, $this> */
    public function attachments(): MorphMany
    {
        return $this->morphMany(Attachment::class, 'attachable');
    }
}
