<?php

namespace App\Models;

use App\Models\Concerns\HasNotesAndAttachments;
use Database\Factories\AssignmentDelayFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AssignmentDelay extends Model
{
    /** @use HasFactory<AssignmentDelayFactory> */
    use HasFactory, HasNotesAndAttachments;

    protected $fillable = [
        'assignment_id',
        'old_date',
        'old_hour',
        'new_date',
        'new_hour',
        'reason',
        'mistaken',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'old_date' => 'date',
            'new_date' => 'date',
            'mistaken' => 'boolean',
        ];
    }

    /** @return BelongsTo<Assignment, $this> */
    public function assignment(): BelongsTo
    {
        return $this->belongsTo(Assignment::class);
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
