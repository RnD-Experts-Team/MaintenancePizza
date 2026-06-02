<?php

namespace App\Models;

use Database\Factories\AssignmentDelayFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AssignmentDelay extends Model
{
    /** @use HasFactory<AssignmentDelayFactory> */
    use HasFactory;

    protected $fillable = [
        'assignment_id',
        'old_date',
        'old_hour',
        'new_date',
        'new_hour',
        'reason',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'old_date' => 'date',
            'new_date' => 'date',
        ];
    }

    /** @return BelongsTo<Assignment, $this> */
    public function assignment(): BelongsTo
    {
        return $this->belongsTo(Assignment::class);
    }
}
