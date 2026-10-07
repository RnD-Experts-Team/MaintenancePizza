<?php

namespace App\Models;

use App\Models\Concerns\HasNotesAndAttachments;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * What to try before opening a ticket for one catalog issue. See the
 * migration for why it is versioned.
 *
 * @property array<int, string> $steps
 */
class TroubleshootingGuide extends Model
{
    use HasNotesAndAttachments;

    protected $fillable = ['issue_id', 'steps', 'link_url'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'steps' => 'array',
            'version' => 'integer',
        ];
    }

    /** @return BelongsTo<Issue, $this> */
    public function issue(): BelongsTo
    {
        return $this->belongsTo(Issue::class)->withTrashed();
    }

    /** @return BelongsTo<User, $this> */
    public function editor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    /** A guide with no steps asks nothing of anyone, so it gates nothing. */
    public function hasSteps(): bool
    {
        return count(array_filter($this->steps ?? [], fn ($s) => trim((string) $s) !== '')) > 0;
    }
}
