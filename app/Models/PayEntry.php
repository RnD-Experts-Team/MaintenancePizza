<?php

namespace App\Models;

use Database\Factories\PayEntryFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class PayEntry extends Model
{
    /** @use HasFactory<PayEntryFactory> */
    use HasFactory;

    protected $fillable = [
        'technician_id',
        'base_pay',
        'performance_pay',
        'driving_time',
        'miles_driven',
        'per_mile_rate',
        'driving_base_pay',
        'driving_performance_pay',
        'mistaken',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'base_pay' => 'decimal:2',
            'performance_pay' => 'decimal:2',
            'driving_time' => 'decimal:2',
            'miles_driven' => 'decimal:2',
            'per_mile_rate' => 'decimal:4',
            'driving_base_pay' => 'decimal:2',
            'driving_performance_pay' => 'decimal:2',
            'mistaken' => 'boolean',
        ];
    }

    /** @return BelongsTo<Technician, $this> */
    public function technician(): BelongsTo
    {
        return $this->belongsTo(Technician::class);
    }

    /** @return BelongsToMany<TicketIssue, $this> */
    public function ticketIssues(): BelongsToMany
    {
        return $this->belongsToMany(TicketIssue::class, 'pay_entry_ticket_issue')->withTimestamps();
    }
}
