<?php

namespace App\Models;

use Database\Factories\NoteFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A free-text note attached polymorphically to any domain entity. Notes are the
 * text sibling of the polymorphic Attachment and may themselves carry files
 * (e.g. a "What we learned" note with a photo).
 */
class Note extends Model
{
    /** @use HasFactory<NoteFactory> */
    // Soft deletes so the daily pay edit path stops hard-deleting notes that
    // its own revision snapshot still points at. Live views are unaffected:
    // morphMany excludes trashed rows by default.
    use HasFactory, SoftDeletes;

    protected $fillable = ['type', 'body'];

    /** A new or removed note is a change to whatever holds it. */
    protected $touches = ['notable'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_private' => 'boolean',
            'locked_at' => 'datetime',
        ];
    }

    /**
     * Who last locked (or unlocked) the note.
     *
     * @return BelongsTo<User, $this>
     */
    public function locker(): BelongsTo
    {
        return $this->belongsTo(User::class, 'locked_by');
    }

    /** @return MorphTo<Model, $this> */
    public function notable(): MorphTo
    {
        return $this->morphTo();
    }

    /** @return MorphMany<Attachment, $this> */
    public function attachments(): MorphMany
    {
        return $this->morphMany(Attachment::class, 'attachable');
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
