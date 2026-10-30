<?php

namespace App\Models;

use App\Enums\Impact;
use App\Enums\IssueStatus;
use App\Enums\Priority;
use App\Enums\Resolution;
use App\Models\Concerns\HasNotesAndAttachments;
use Database\Factories\TicketIssueFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One problem on a ticket, and the unit the v2 workflow moves
 * (docs/MAINTENANCE-V2.md §2): new → in_review → … → closed.
 *
 * The workflow columns are what each step writes down: `impact` and the
 * contact at submission; `target_completion_at` / `next_action` at review;
 * `work_performed` when the work is done; `store_confirmed_*` when the store
 * says it works (or not); `resolution` and `closed_at` when it closes.
 */
class TicketIssue extends Model
{
    /** @use HasFactory<TicketIssueFactory> */
    use HasFactory, HasNotesAndAttachments;

    protected $fillable = [
        'ticket_id',
        'issue_id',
        'other_title',
        'priority',
        'assigned_priority',
        'description',
        'status',
        'parent_id',
        'impact',
        'contact_name',
        'contact_phone',
        'target_completion_at',
        'next_action',
        'work_performed',
        'resolution',
        'store_confirmed_at',
        'store_confirmed_working',
        'store_confirmed_via',
        'store_confirmed_by',
        'closed_at',
    ];

    /** Any change to an issue is a change to its ticket. */
    protected $touches = ['ticket'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'priority' => Priority::class,
            'assigned_priority' => Priority::class,
            'status' => IssueStatus::class,
            'impact' => Impact::class,
            'resolution' => Resolution::class,
            'target_completion_at' => 'datetime',
            'store_confirmed_at' => 'datetime',
            'store_confirmed_working' => 'boolean',
            'closed_at' => 'datetime',
            'troubleshooting_confirmed_at' => 'datetime',
            'troubleshooting_snapshot' => 'array',
        ];
    }

    /**
     * The label to show for this issue: the catalog title or the free-text "other".
     */
    public function displayTitle(): string
    {
        return $this->issue?->title ?? (string) $this->other_title;
    }

    /** @return BelongsTo<Ticket, $this> */
    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class);
    }

    /** @return BelongsTo<Issue, $this> */
    public function issue(): BelongsTo
    {
        return $this->belongsTo(Issue::class);
    }

    /** @return BelongsTo<TicketIssue, $this> */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(TicketIssue::class, 'parent_id');
    }

    /** @return HasMany<TicketIssue, $this> */
    public function children(): HasMany
    {
        return $this->hasMany(TicketIssue::class, 'parent_id');
    }

    /** @return HasMany<IssueStatusChange, $this> */
    public function statusChanges(): HasMany
    {
        return $this->hasMany(IssueStatusChange::class);
    }

    /**
     * The bookings on this issue (`kind` work|inspection) -- what the contract
     * calls its schedules.
     *
     * @return BelongsToMany<Assignment, $this>
     */
    public function assignments(): BelongsToMany
    {
        return $this->belongsToMany(Assignment::class, 'assignment_ticket_issue')->withTimestamps();
    }

    /**
     * Alias of assignments(), in the contract's word.
     *
     * @return BelongsToMany<Assignment, $this>
     */
    public function schedules(): BelongsToMany
    {
        return $this->assignments();
    }

    /** @return HasMany<IssueInfoRequest, $this> */
    public function infoRequests(): HasMany
    {
        return $this->hasMany(IssueInfoRequest::class)->orderBy('id');
    }

    /** @return HasMany<IssueInspection, $this> */
    public function inspections(): HasMany
    {
        return $this->hasMany(IssueInspection::class)->orderBy('id');
    }

    /** @return HasMany<IssueApproval, $this> */
    public function approvals(): HasMany
    {
        return $this->hasMany(IssueApproval::class)->orderBy('id');
    }

    /**
     * The trip visits this issue is on.
     *
     * @return HasMany<TripStopIssue, $this>
     */
    public function tripStopIssues(): HasMany
    {
        return $this->hasMany(TripStopIssue::class);
    }

    /**
     * The payment lines that paid for this issue.
     *
     * @return BelongsToMany<PaymentLine, $this>
     */
    public function paymentLines(): BelongsToMany
    {
        return $this->belongsToMany(PaymentLine::class, 'payment_line_ticket_issue')->withTimestamps();
    }

    /** @return HasMany<TechnicianPurchase, $this> */
    public function technicianPurchases(): HasMany
    {
        return $this->hasMany(TechnicianPurchase::class);
    }

    /**
     * Stock movements caused by this issue (parts Used on it from storage).
     *
     * @return HasMany<StockMovement, $this>
     */
    public function stockMovements(): HasMany
    {
        return $this->hasMany(StockMovement::class);
    }

    /** @return HasMany<PartUnit, $this> */
    public function partUnits(): HasMany
    {
        return $this->hasMany(PartUnit::class);
    }

    /** @return BelongsToMany<Warranty, $this> */
    public function warranties(): BelongsToMany
    {
        return $this->belongsToMany(Warranty::class, 'warranty_ticket_issue')->withTimestamps();
    }

    /**
     * The technicians put on this issue (the people it is assigned to).
     *
     * @return BelongsToMany<Technician, $this>
     */
    public function technicians(): BelongsToMany
    {
        return $this->belongsToMany(Technician::class, 'technician_ticket_issue')
            ->withPivot('created_by')
            ->withTimestamps();
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return BelongsTo<User, $this> */
    public function storeConfirmer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'store_confirmed_by');
    }
}
