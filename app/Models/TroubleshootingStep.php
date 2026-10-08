<?php

namespace App\Models;

use App\Models\Concerns\HasNotesAndAttachments;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One step of a troubleshooting guide, with its own files (a photo of the
 * breaker, a short video of the reset).
 */
class TroubleshootingStep extends Model
{
    use HasNotesAndAttachments;

    protected $fillable = ['troubleshooting_guide_id', 'position', 'body'];

    /** Adding a file to a step changes the guide. */
    protected $touches = ['guide'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'position' => 'integer',
        ];
    }

    /** @return BelongsTo<TroubleshootingGuide, $this> */
    public function guide(): BelongsTo
    {
        return $this->belongsTo(TroubleshootingGuide::class, 'troubleshooting_guide_id');
    }
}
