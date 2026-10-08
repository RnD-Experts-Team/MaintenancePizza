<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A store's problem that troubleshooting fixed, so no ticket was opened.
 * `snapshot` is the guide as the manager saw it (null guide = they did not say
 * which one).
 */
class TroubleshootingFix extends Model
{
    protected $fillable = ['store_id', 'issue_id', 'troubleshooting_guide_id', 'snapshot'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'snapshot' => 'array',
        ];
    }

    /** @return BelongsTo<Store, $this> */
    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    /** @return BelongsTo<Issue, $this> */
    public function issue(): BelongsTo
    {
        return $this->belongsTo(Issue::class)->withTrashed();
    }

    /** @return BelongsTo<TroubleshootingGuide, $this> */
    public function guide(): BelongsTo
    {
        return $this->belongsTo(TroubleshootingGuide::class, 'troubleshooting_guide_id');
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
