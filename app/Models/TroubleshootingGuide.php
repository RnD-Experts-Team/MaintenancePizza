<?php

namespace App\Models;

use App\Models\Concerns\HasNotesAndAttachments;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * What to try for one specific problem with a catalog issue ("Oven" -> "Won't
 * heat"). An issue can have several. Versioned, so a ticket can record exactly
 * which steps the manager tried. Its own files and link are for the whole
 * guide; each step carries its own files too.
 */
class TroubleshootingGuide extends Model
{
    use HasNotesAndAttachments;

    protected $fillable = ['issue_id', 'title', 'link_url'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'version' => 'integer',
        ];
    }

    /** @return BelongsTo<Issue, $this> */
    public function issue(): BelongsTo
    {
        return $this->belongsTo(Issue::class)->withTrashed();
    }

    /** @return HasMany<TroubleshootingStep, $this> */
    public function steps(): HasMany
    {
        return $this->hasMany(TroubleshootingStep::class)->orderBy('position')->orderBy('id');
    }

    /** @return HasMany<TroubleshootingFix, $this> */
    public function fixes(): HasMany
    {
        return $this->hasMany(TroubleshootingFix::class);
    }

    /**
     * Tickets opened after trying this guide: it did not fix the problem.
     *
     * @return HasMany<TicketIssue, $this>
     */
    public function triedOn(): HasMany
    {
        return $this->hasMany(TicketIssue::class)->where('troubleshooting_outcome', 'tried');
    }

    /** @return BelongsTo<User, $this> */
    public function editor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
