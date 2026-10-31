<?php

namespace App\Services;

use App\Enums\IssueStatus;
use App\Enums\PaymentLineType;
use App\Enums\StockMovementType;
use App\Models\Assignment;
use App\Models\AssignmentDelay;
use App\Models\Attachment;
use App\Models\IssueApproval;
use App\Models\IssueInfoRequest;
use App\Models\IssueInspection;
use App\Models\IssueStatusChange;
use App\Models\Note;
use App\Models\PaymentLine;
use App\Models\StockMovement;
use App\Models\StockMovementLine;
use App\Models\Technician;
use App\Models\TechnicianPurchase;
use App\Models\TicketIssue;
use App\Models\TripStopIssue;
use App\Models\User;
use App\Models\Warranty;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * The full read of one ticket issue (docs/MAINTENANCE.md §2 "Read"): the
 * core fields, everyone and everything on it -- technicians, questions to the
 * store, inspections, approvals, bookings, visits, money, stock -- the
 * workflow block (step, what can be done next, the close checklist) and one
 * merged timeline.
 *
 * It only ever reads what is LOADED. with() is the eager-load list for the
 * full read (the issue page and a ticket's issue list, no N+1 however many
 * issues); lighter callers (the ticket list) load less, and every section
 * whose relations are not loaded comes out null rather than querying per
 * row.
 *
 * Payments, trips and stock are read straight off their models: their own
 * endpoints belong to other areas, but the tables are shared.
 */
class TicketIssuePresenter
{
    public function __construct(
        private CatalogService $catalog,
        private NoteService $notes,
        private AttachmentService $attachments,
        private IssueWorkflowService $workflow,
        private MaintenanceSettingsService $settings,
    ) {
    }

    /**
     * Eager loads for the full read.
     *
     * @return array<int|string, mixed>
     */
    public static function with(): array
    {
        $files = fn (string $rel) => [$rel.'.attachments.creator', $rel.'.notes.creator', $rel.'.notes.locker', $rel.'.notes.attachments.creator'];

        return [
            'issue' => fn ($q) => $q->withTrashed(),
            'parent',
            'children.issue' => fn ($q) => $q->withTrashed(),
            'creator',
            'storeConfirmer',
            'statusChanges.creator',
            'notes.creator',
            'notes.locker',
            'notes.attachments.creator',
            'attachments.creator',
            'warranties.creator',
            ...$files('warranties'),
            'technicians' => fn ($q) => $q->withTrashed()->with('category'),
            'infoRequests.asker',
            'infoRequests.answerer',
            ...$files('infoRequests'),
            'inspections.technician',
            'inspections.creator',
            ...$files('inspections'),
            'approvals.creator',
            ...$files('approvals'),
            'assignments.technician',
            'assignments.creator',
            'assignments.delays.creator',
            'tripStopIssues.stop.store',
            'tripStopIssues.stop.trip.technician',
            'paymentLines.payment.technician',
            'paymentLines.payment.supplier',
            'paymentLines.payment.creator',
            'paymentLines.payment.attachments',
            'technicianPurchases.technician',
            'technicianPurchases.part',
            'technicianPurchases.supplier',
            'technicianPurchases.paymentLine',
            'technicianPurchases.attachments.creator',
            'stockMovements.creator',
            'stockMovements.storageLocation',
            'stockMovements.lines.part' => fn ($q) => $q->withTrashed(),
            'stockMovements.lines.storageLocation' => fn ($q) => $q->withTrashed(),
            'stockMovements.lines.units',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function present(TicketIssue $issue): array
    {
        $full = $issue->relationLoaded('paymentLines');

        $core = [
            'id' => $issue->id,
            'ticket_id' => $issue->ticket_id,
            'title' => $issue->displayTitle(),
            'display_title' => $issue->displayTitle(),
            'issue_id' => $issue->issue_id,
            'issue' => $issue->relationLoaded('issue') && $issue->issue
                ? $this->catalog->presentIssue($issue->issue)
                : null,
            'other_title' => $issue->other_title,
            'description' => $issue->description,
            'priority' => $this->enum($issue->priority),
            'assigned_priority' => $this->enum($issue->assigned_priority),
            'impact' => $this->enum($issue->impact),
            'contact_name' => $issue->contact_name,
            'contact_phone' => $issue->contact_phone,
            'status' => $issue->status->value,
            'status_label' => $issue->status->label(),
            'whose_turn' => $issue->status->whoseTurn(),
            'step' => $this->workflow->stepOf($issue->status),
            'target_completion_at' => $issue->target_completion_at,
            'next_action' => $issue->next_action,
            'work_performed' => $issue->work_performed,
            'resolution' => $this->enum($issue->resolution),
            'store_confirmed_at' => $issue->store_confirmed_at,
            'store_confirmed_working' => $issue->store_confirmed_working,
            'store_confirmed_via' => $issue->store_confirmed_via,
            'store_confirmed_by' => $issue->relationLoaded('storeConfirmer') && $issue->storeConfirmer
                ? $this->user($issue->storeConfirmer)
                : null,
            'closed_at' => $issue->closed_at,
            'parent_id' => $issue->parent_id,
            'children' => $issue->relationLoaded('children')
                ? $issue->children->map(fn (TicketIssue $c) => $this->present($c))->all()
                : null,
            'created_by' => $issue->created_by,
            'creator' => $issue->relationLoaded('creator') && $issue->creator ? $this->user($issue->creator) : null,
            'created_at' => $issue->created_at,
            'updated_at' => $issue->updated_at,
        ];

        $sections = [
            'technicians' => $this->loaded($issue, 'technicians', fn (Technician $t) => $this->technician($t)),
            'info_requests' => $this->loaded($issue, 'infoRequests', fn (IssueInfoRequest $r) => $this->infoRequest($r)),
            'inspections' => $this->loaded($issue, 'inspections', fn (IssueInspection $i) => $this->inspection($i)),
            'approvals' => $this->loaded($issue, 'approvals', fn (IssueApproval $a) => $this->approval($a)),
            'schedules' => $this->loaded($issue, 'assignments', fn (Assignment $a) => $this->schedule($a)),
            'visits' => $issue->relationLoaded('tripStopIssues') ? $this->visits($issue) : null,
            'costs' => $full ? $this->costs($issue) : null,
            'payments' => $full ? $this->payments($issue) : null,
            'purchases' => $this->loaded($issue, 'technicianPurchases', fn (TechnicianPurchase $p) => $this->purchase($p)),
            'stock_used' => $issue->relationLoaded('stockMovements') ? $this->stockUsed($issue) : null,
            'warranties' => $this->loaded($issue, 'warranties', fn (Warranty $w) => $this->warranty($w)),
            'workflow' => $full ? $this->workflowBlock($issue) : null,
            'status_changes' => $this->loaded($issue, 'statusChanges', fn (IssueStatusChange $c) => $this->statusChange($c)),
            'notes' => $this->notes->presentMany($issue),
            'attachments' => $this->attachments->presentMany($issue),
            // The troubleshooting the manager confirmed trying before opening
            // the ticket, as it read then. Null when none was asked for.
            'troubleshooting_confirmed_at' => $issue->troubleshooting_confirmed_at,
            'troubleshooting_outcome' => $issue->troubleshooting_outcome,
            'troubleshooting_guide_id' => $issue->troubleshooting_guide_id,
            'troubleshooting_snapshot' => $issue->troubleshooting_snapshot,
        ];

        $sections['timeline'] = $full ? $this->timeline($issue) : null;

        return $core + $sections;
    }

    // -------------------------------------------------------------- Sections

    /**
     * @return array<string, mixed>
     */
    private function technician(Technician $t): array
    {
        return [
            'id' => $t->id,
            'name' => $t->name,
            'phone' => $t->phone,
            'category' => $t->relationLoaded('category') && $t->category
                ? ['id' => $t->category->id, 'name' => $t->category->name]
                : null,
            'deleted_at' => $t->deleted_at,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function infoRequest(IssueInfoRequest $r): array
    {
        return [
            'id' => $r->id,
            'question' => $r->question,
            'asked_by' => $r->asker ? $this->user($r->asker) : null,
            'created_at' => $r->created_at,
            'answer' => $r->answer,
            'answered_via' => $r->answered_via,
            'answered_by' => $r->answerer ? $this->user($r->answerer) : null,
            'answered_at' => $r->answered_at,
            'mistaken' => (bool) $r->mistaken,
            'attachments' => $this->attachments->presentMany($r),
            'notes' => $this->notes->presentMany($r),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function inspection(IssueInspection $i): array
    {
        return [
            'id' => $i->id,
            'technician' => $i->technician ? ['id' => $i->technician->id, 'name' => $i->technician->name] : null,
            'status' => $i->status->value,
            'status_label' => $i->status->label(),
            'scheduled_date' => $i->scheduled_date?->toDateString(),
            'scheduled_time' => AssignmentService::showTime($i->scheduled_time),
            'done_at' => $i->done_at,
            'findings' => $i->findings,
            'recommended_repair' => $i->recommended_repair,
            'parts_needed' => $i->parts_needed,
            'estimated_cost' => $this->money($i->estimated_cost),
            'attachments' => $this->attachments->presentMany($i),
            'notes' => $this->notes->presentMany($i),
            'mistaken' => (bool) $i->mistaken,
            'created_at' => $i->created_at,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function approval(IssueApproval $a): array
    {
        return [
            'id' => $a->id,
            'amount' => $this->money($a->amount),
            'level' => $a->level,
            'level_label' => $this->settings->labelOf((string) $a->level),
            'decision' => $a->decision->value,
            'decision_label' => $a->decision->label(),
            'approver_name' => $a->approver_name,
            'decided_at' => $a->decided_at,
            'note' => $a->note,
            'inspection_id' => $a->issue_inspection_id,
            'attachments' => $this->attachments->presentMany($a),
            'notes' => $this->notes->presentMany($a),
            'mistaken' => (bool) $a->mistaken,
            'created_by' => $a->relationLoaded('creator') && $a->creator ? $this->user($a->creator) : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function schedule(Assignment $a): array
    {
        return [
            'id' => $a->id,
            'kind' => $a->kind ?? 'work',
            'technician' => $a->technician ? ['id' => $a->technician->id, 'name' => $a->technician->name] : null,
            'date' => $a->assigned_date?->toDateString(),
            'time' => AssignmentService::showTime($a->assigned_hour),
            'expected_completion_date' => $a->expected_completion_date?->toDateString(),
            'trip_id' => $a->trip_id,
            'inspection_id' => $a->issue_inspection_id,
            'mistaken' => (bool) $a->mistaken,
            'delays' => $a->relationLoaded('delays')
                ? $a->delays->sortBy('id')->map(fn (AssignmentDelay $d) => [
                    'id' => $d->id,
                    'old_date' => $d->old_date?->toDateString(),
                    'old_time' => AssignmentService::showTime($d->old_hour),
                    'new_date' => $d->new_date?->toDateString(),
                    'new_time' => AssignmentService::showTime($d->new_hour),
                    'reason' => $d->reason,
                    'mistaken' => (bool) $d->mistaken,
                    'created_by' => $d->relationLoaded('creator') && $d->creator ? $this->user($d->creator) : null,
                    'created_at' => $d->created_at,
                ])->values()->all()
                : null,
            'created_by' => $a->relationLoaded('creator') && $a->creator ? $this->user($a->creator) : null,
            'created_at' => $a->created_at,
        ];
    }

    /**
     * The issue on technicians' trips, mistaken trips and stops left out.
     *
     * @return array<int, array<string, mixed>>
     */
    private function visits(TicketIssue $issue): array
    {
        return $this->liveVisits($issue)
            ->map(function (TripStopIssue $row) {
                $stop = $row->stop;
                $trip = $stop->trip;

                return [
                    'trip_id' => $trip->id,
                    'trip_stop_id' => $stop->id,
                    'technician' => $trip->technician ? ['id' => $trip->technician->id, 'name' => $trip->technician->name] : null,
                    'planned_date' => $trip->planned_date?->toDateString(),
                    'trip_status' => $trip->status?->value,
                    'store_number' => $stop->store?->store_number,
                    'other_store' => $stop->other_store,
                    'stop_status' => $stop->status,
                    'arrived_at' => $stop->arrived_at,
                    'left_at' => $stop->left_at,
                    'purpose' => $row->purpose,
                    'task_status' => $row->task_status,
                    'done_at' => $row->done_at,
                    'not_done_reason' => $row->not_done_reason,
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @return Collection<int, TripStopIssue>
     */
    private function liveVisits(TicketIssue $issue): Collection
    {
        return $issue->tripStopIssues
            ->filter(fn (TripStopIssue $r) => $r->stop !== null && !$r->stop->mistaken && $r->stop->trip !== null && !$r->stop->trip->mistaken)
            ->sortBy(fn (TripStopIssue $r) => sprintf('%s-%010d', $r->stop->trip->planned_date?->toDateString() ?? '', $r->id))
            ->values();
    }

    /**
     * The payment lines on the issue that count: their payment exists and is
     * not mistaken.
     *
     * @return Collection<int, PaymentLine>
     */
    private function liveLines(TicketIssue $issue): Collection
    {
        return $issue->paymentLines->filter(fn (PaymentLine $l) => $l->payment !== null && !$l->payment->mistaken)->values();
    }

    /**
     * What the issue cost. Each payment line linked to it counts in full
     * (a line linked to two issues counts on both, as it does on the
     * payments page). `total_actual` is the payment lines plus the stock used
     * from storage at cost; unpaid technician purchases are shown apart --
     * they are not paid yet, and once a parts line pays them back that line
     * is what counts.
     *
     * @return array<string, string|null>
     */
    private function costs(TicketIssue $issue): array
    {
        $sum = array_fill_keys(PaymentLineType::values(), '0.00');
        foreach ($this->liveLines($issue) as $line) {
            $type = $line->type->value;
            $sum[$type] = bcadd($sum[$type], (string) $line->amount, 2);
        }

        $stock = '0.00';
        foreach ($this->usedLines($issue) as [, $line]) {
            $stock = bcadd($stock, $this->lineValue($line), 2);
        }

        $unpaid = '0.00';
        if ($issue->relationLoaded('technicianPurchases')) {
            foreach ($issue->technicianPurchases as $p) {
                if (!$p->mistaken && $p->payment_line_id === null) {
                    $unpaid = bcadd($unpaid, (string) $p->total_cost, 2);
                }
            }
        }

        $labor = bcadd($sum['hourly'], $sum['fixed'], 2);
        $paid = array_reduce(array_values($sum), fn (string $c, string $v) => bcadd($c, $v, 2), '0.00');

        return [
            'estimated' => $this->estimated($issue),
            'approved' => $this->approved($issue),
            'labor' => $labor,
            'inspection' => $sum['inspection'],
            'parts' => $sum['parts'],
            'gas' => $sum['gas'],
            'other' => $sum['other'],
            'paid_total' => $paid,
            'stock_used_value' => $stock,
            'total_actual' => bcadd($paid, $stock, 2),
            'unpaid_purchases' => $unpaid,
        ];
    }

    private function estimated(TicketIssue $issue): ?string
    {
        if (!$issue->relationLoaded('inspections')) {
            return null;
        }

        $latest = $issue->inspections
            ->filter(fn (IssueInspection $i) => !$i->mistaken && $i->estimated_cost !== null)
            ->sortBy('id')
            ->last();

        return $latest ? $this->money($latest->estimated_cost) : null;
    }

    private function approved(TicketIssue $issue): ?string
    {
        if (!$issue->relationLoaded('approvals')) {
            return null;
        }

        $latest = $issue->approvals
            ->filter(fn (IssueApproval $a) => !$a->mistaken && $a->decision->value === 'approved')
            ->sortBy('id')
            ->last();

        return $latest ? $this->money($latest->amount) : null;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function payments(TicketIssue $issue): array
    {
        return $this->liveLines($issue)
            ->sortByDesc(fn (PaymentLine $l) => sprintf('%s-%010d', $l->payment->paid_at?->toDateString() ?? '', $l->id))
            ->map(fn (PaymentLine $l) => [
                'payment_id' => $l->payment_id,
                'line_id' => $l->id,
                'paid_at' => $l->payment->paid_at?->toDateString(),
                'paid_to' => $l->payment->paidTo(),
                'type' => $l->type->value,
                'type_label' => $l->type->label(),
                'description' => $l->description,
                'amount' => $this->money($l->amount),
                'invoice_number' => $l->payment->invoice_number,
                'has_invoice' => $l->payment->relationLoaded('attachments') && $l->payment->attachments->isNotEmpty(),
                // The files on that payment (the invoice and anything else).
                'invoices' => $l->payment->relationLoaded('attachments')
                    ? $l->payment->attachments->map(fn ($a) => [
                        'id' => $a->id,
                        'original_name' => $a->original_name,
                        'url' => $a->url,
                    ])->values()->all()
                    : [],
            ])
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function purchase(TechnicianPurchase $p): array
    {
        return [
            'id' => $p->id,
            'technician' => $p->technician ? ['id' => $p->technician->id, 'name' => $p->technician->name] : null,
            'trip_id' => $p->trip_id,
            'ticket_issue_id' => $p->ticket_issue_id,
            'part' => $p->part ? ['id' => $p->part->id, 'name' => $p->part->name] : null,
            'description' => $p->description,
            'quantity' => $p->quantity,
            'total_cost' => $this->money($p->total_cost),
            'supplier' => $p->supplier ? ['id' => $p->supplier->id, 'name' => $p->supplier->name] : null,
            'serial_number' => $p->serial_number,
            'bought_at' => $p->bought_at,
            'paid' => $p->payment_line_id !== null,
            'payment_id' => $p->paymentLine?->payment_id,
            'receipts' => $this->attachments->presentMany($p),
            'mistaken' => (bool) $p->mistaken,
        ];
    }

    /**
     * Each line of each Used movement on the issue that was not undone.
     *
     * @return array<int, array{0: StockMovement, 1: StockMovementLine}>
     */
    private function usedLines(TicketIssue $issue): array
    {
        if (!$issue->relationLoaded('stockMovements')) {
            return [];
        }

        $pairs = [];
        foreach ($issue->stockMovements as $movement) {
            if ($movement->type !== StockMovementType::Used || $movement->mistaken) {
                continue;
            }
            foreach ($movement->lines as $line) {
                $pairs[] = [$movement, $line];
            }
        }

        return $pairs;
    }

    private function lineValue(StockMovementLine $line): string
    {
        if ($line->total_cost !== null) {
            return bcadd((string) $line->total_cost, '0', 2);
        }

        if ($line->unit_cost !== null) {
            return bcmul((string) $line->quantity, (string) $line->unit_cost, 2);
        }

        return '0.00';
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function stockUsed(TicketIssue $issue): array
    {
        $rows = [];
        foreach ($this->usedLines($issue) as [$movement, $line]) {
            $location = $line->storageLocation ?? $movement->storageLocation;

            $rows[] = [
                'movement_id' => $movement->id,
                'line_id' => $line->id,
                'moved_at' => $movement->moved_at,
                'part' => $line->part ? ['id' => $line->part->id, 'name' => $line->part->name] : null,
                'quantity' => $line->quantity,
                'value' => $this->lineValue($line),
                'storage_location' => $location ? ['id' => $location->id, 'name' => $location->name] : null,
                'serials' => $line->relationLoaded('units') ? $line->units->pluck('serial_number')->values()->all() : [],
            ];
        }

        usort($rows, fn (array $a, array $b) => [$b['moved_at'], $b['line_id']] <=> [$a['moved_at'], $a['line_id']]);

        return $rows;
    }

    /**
     * @return array<string, mixed>
     */
    public function warranty(Warranty $warranty): array
    {
        return [
            'id' => $warranty->id,
            'body' => $warranty->body,
            'expiry_date' => $warranty->expiry_date?->toDateString(),
            'mistaken' => $warranty->mistaken,
            'attachments' => $this->attachments->presentMany($warranty),
            'notes' => $this->notes->presentMany($warranty),
            'created_by' => $warranty->created_by,
            'creator' => $warranty->relationLoaded('creator') && $warranty->creator
                ? $this->catalog->presentUser($warranty->creator)
                : null,
            'created_at' => $warranty->created_at,
            'updated_at' => $warranty->updated_at,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function statusChange(IssueStatusChange $change): array
    {
        return [
            'id' => $change->id,
            'ticket_issue_id' => $change->ticket_issue_id,
            'from_status' => $change->from_status?->value,
            'from_label' => $change->from_status?->label(),
            'to_status' => $change->to_status->value,
            'to_label' => $change->to_status->label(),
            'reason' => $change->reason,
            'created_by' => $change->created_by,
            'creator' => $change->relationLoaded('creator') && $change->creator
                ? $this->catalog->presentUser($change->creator)
                : null,
            'created_at' => $change->created_at,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function workflowBlock(TicketIssue $issue): array
    {
        $estimate = $this->estimated($issue);

        return [
            'step' => $this->workflow->stepOf($issue->status),
            'status' => $issue->status->value,
            'status_label' => $issue->status->label(),
            'whose_turn' => $issue->status->whoseTurn(),
            'next' => $this->workflow->next($issue),
            'checklist' => $this->workflow->checklist($issue),
            // Who would have to approve the amount on the table: the latest
            // inspection estimate. Null until there is one.
            'approval_level_for' => $estimate === null ? null : $this->workflow->approvalLevelFor($estimate),
        ];
    }

    // -------------------------------------------------------------- Timeline

    /**
     * Everything that happened to the issue, newest first: {at, kind, text,
     * actor}. Private notes never appear (as in every embedded read).
     *
     * @return array<int, array{at: mixed, kind: string, text: string, actor: string|null}>
     */
    private function timeline(TicketIssue $issue): array
    {
        $events = [];
        $add = function ($at, string $kind, string $text, ?string $actor) use (&$events) {
            if ($at !== null) {
                $events[] = ['at' => Carbon::parse($at), 'kind' => $kind, 'text' => $text, 'actor' => $actor];
            }
        };

        foreach ($issue->infoRequests as $r) {
            if ($r->mistaken) {
                continue;
            }
            $add($r->created_at, 'info_requested', 'Asked the store: '.$r->question, $r->asker?->name);
            if ($r->answered_at !== null) {
                $who = $r->answered_via === 'specialist' ? "The store's answer (by phone)" : 'The store answered';
                $add($r->answered_at, 'info_answered', "{$who}: {$r->answer}", $r->answerer?->name);
            }
        }

        foreach ($issue->inspections as $i) {
            if ($i->mistaken) {
                continue;
            }
            $tech = $i->technician?->name ?? 'a technician';
            $when = $i->scheduled_date?->toDateString().($i->scheduled_time ? ' at '.AssignmentService::showTime($i->scheduled_time) : '');
            $add($i->created_at, 'inspection_booked', "Inspection booked with {$tech} for {$when}", $i->creator?->name);
            if ($i->done_at !== null) {
                $estimate = $i->estimated_cost !== null ? ' (estimate '.$this->money($i->estimated_cost).')' : '';
                $add($i->done_at, 'inspection_done', 'Inspection done: '.Str::limit((string) $i->findings, 200).$estimate, $tech);
            }
        }

        foreach ($issue->approvals as $a) {
            if ($a->mistaken) {
                continue;
            }
            $label = $this->settings->labelOf((string) $a->level);
            $add($a->decided_at, 'approval', "{$a->decision->label()} by {$a->approver_name} ({$label}) for ".$this->money($a->amount), $a->creator?->name);
        }

        foreach ($issue->assignments as $s) {
            if ($s->mistaken) {
                continue;
            }
            // An inspection booking is already on the timeline as the inspection.
            if (($s->kind ?? 'work') === 'work') {
                $when = $s->assigned_date?->toDateString().($s->assigned_hour ? ' at '.AssignmentService::showTime($s->assigned_hour) : '');
                $add($s->created_at, 'scheduled', 'Work booked with '.($s->technician?->name ?? 'a technician')." for {$when}", $s->creator?->name);
            }
            foreach ($s->delays as $d) {
                if ($d->mistaken) {
                    continue;
                }
                $add($d->created_at, 'schedule_moved', "Booking moved from {$d->old_date?->toDateString()} to {$d->new_date?->toDateString()}: {$d->reason}", $d->creator?->name);
            }
        }

        foreach ($this->liveVisits($issue) as $row) {
            $stop = $row->stop;
            $tech = $stop->trip->technician?->name;
            $where = $stop->store?->store_number ?? $stop->other_store ?? 'the store';
            $add($stop->arrived_at, 'visit_arrived', "Arrived at {$where}", $tech);
            $add($stop->left_at, 'visit_left', "Left {$where}", $tech);
            if ($row->task_status === 'done') {
                $add($row->done_at, 'visit_done', $row->purpose === 'inspection' ? 'Finished the inspection' : 'Finished the work', $tech);
            } elseif ($row->task_status === 'not_done') {
                $add($row->updated_at, 'visit_not_done', 'Could not finish'.($row->not_done_reason ? ": {$row->not_done_reason}" : ''), $tech);
            }
        }

        foreach ($this->liveLines($issue) as $l) {
            $to = $l->payment->paidTo()['name'] ?? 'someone';
            $add($l->payment->created_at, 'payment', "Paid {$to} ".$this->money($l->amount)." ({$l->type->label()}) on {$l->payment->paid_at?->toDateString()}", $l->payment->creator?->name);
        }

        foreach ($issue->technicianPurchases as $p) {
            if ($p->mistaken) {
                continue;
            }
            $add($p->bought_at, 'purchase', "Bought {$p->description} for ".$this->money($p->total_cost), $p->technician?->name);
        }

        foreach ($this->usedLines($issue) as [$m, $line]) {
            $location = ($line->storageLocation ?? $m->storageLocation)?->name;
            $add($m->moved_at, 'stock_used', 'Used '.$this->quantity($line->quantity).' × '.($line->part?->name ?? 'a part').($location ? " from {$location}" : ''), $m->creator?->name);
        }

        foreach ($issue->notes as $n) {
            if ($n->is_private) {
                continue;
            }
            $add($n->created_at, 'note', 'Note: '.Str::limit((string) $n->body, 200), $n->creator?->name);
        }

        if ($issue->store_confirmed_at !== null) {
            $how = $issue->store_confirmed_via === 'specialist' ? ' (told the specialist)' : '';
            $add($issue->store_confirmed_at, 'store_confirmed', ($issue->store_confirmed_working ? 'The store says it works now' : 'The store says it still does not work').$how, $issue->storeConfirmer?->name);
        }

        // Status changes go in last so that, within the same second, the
        // change a step caused shows above the step itself.
        foreach ($issue->statusChanges as $c) {
            if ($c->from_status === $c->to_status) {
                continue;
            }
            $text = ($c->from_status ? "{$c->from_status->label()} → " : 'Opened as ').$c->to_status->label();
            $add($c->created_at, 'status_changed', $c->reason ? "{$text}: {$c->reason}" : $text, $c->creator?->name);
        }

        // Newest first; within the same second, the one added later first.
        $indexed = array_map(null, $events, array_keys($events));
        usort($indexed, fn ($a, $b) => [$b[0]['at']->getTimestamp(), $b[1]] <=> [$a[0]['at']->getTimestamp(), $a[1]]);

        return array_map(fn ($pair) => $pair[0], $indexed);
    }

    // --------------------------------------------------------------- Helpers

    /**
     * @return array{value: string, label: string}|null
     */
    private function enum(mixed $case): ?array
    {
        return $case === null ? null : ['value' => $case->value, 'label' => $case->label()];
    }

    /**
     * @return array{id: int, name: string}
     */
    private function user(User $user): array
    {
        return ['id' => $user->id, 'name' => $user->name];
    }

    private function money(mixed $amount): ?string
    {
        return $amount === null ? null : number_format((float) $amount, 2, '.', '');
    }

    private function quantity(mixed $quantity): string
    {
        $q = (string) $quantity;

        return str_contains($q, '.') ? rtrim(rtrim($q, '0'), '.') : $q;
    }

    /**
     * @return array<int, mixed>|null
     */
    private function loaded(TicketIssue $issue, string $relation, callable $present): ?array
    {
        return $issue->relationLoaded($relation) ? $issue->{$relation}->map($present)->values()->all() : null;
    }
}
