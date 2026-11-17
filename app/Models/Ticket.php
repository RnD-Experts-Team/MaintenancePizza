<?php

namespace App\Models;

use App\Enums\TicketType;
use App\Models\Concerns\HasNotesAndAttachments;
use Database\Factories\TicketFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Ticket extends Model
{
    /** @use HasFactory<TicketFactory> */
    use HasFactory, HasNotesAndAttachments, SoftDeletes;

    protected $fillable = ['store_id', 'other_store', 'storage_location_id', 'type'];

    protected $casts = [
        'type' => TicketType::class,
        // When its Store Managers were last told it changed. Set on
        // creation too: the person who opens a ticket knows about it.
        'last_notified_at' => 'datetime',
    ];

    /** @return BelongsTo<Store, $this> */
    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    /**
     * The storage location it is at, when not at a store. Soft-deleted
     * locations still name their old tickets.
     *
     * @return BelongsTo<StorageLocation, $this>
     */
    public function storageLocation(): BelongsTo
    {
        return $this->belongsTo(StorageLocation::class)->withTrashed();
    }

    /**
     * Where it is, as people read it: the store number, the storage
     * location's name, or the place written in words.
     */
    public function placeLabel(): ?string
    {
        return $this->store?->store_number ?? $this->storageLocation?->name ?? $this->other_store;
    }

    /** @return HasMany<TicketIssue, $this> */
    public function ticketIssues(): HasMany
    {
        return $this->hasMany(TicketIssue::class);
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
