<?php

namespace App\Services;

use App\Enums\FinalNoteType;
use App\Enums\InspectionStatus;
use App\Enums\IssueStatus;
use App\Enums\Resolution;
use App\Models\Assignment;
use App\Models\IssueInfoRequest;
use App\Models\IssueInspection;
use App\Models\Payment;
use App\Models\PaymentLine;
use App\Models\TicketIssue;
use App\Models\TripStopIssue;
use App\Models\User;
use App\Models\Warranty;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * The status machine of one ticket issue (docs/MAINTENANCE.md §2): which
 * workflow action may run in which status, why not in plain words, what the
 * progress strip shows (stepOf), what can be done next (next), and the close
 * checklist.
 *
 *   new → in_review → (waiting_on_store) → (inspection) → (waiting_approval)
 *       → scheduled → work_done → closed          (or cancelled at any point)
 *
 * The machine is deliberately a little forgiving where the office really
 * works that way -- a new issue can be scheduled straight away (scheduling
 * it IS reviewing it), a second inspection can be booked while the first is
 * out -- and `jump` is the escape hatch for everything else: any status to
 * any status, with a reason, logged.
 *
 * Refusals are 422s whose `errors.status` says why, in the words the
 * dashboard shows.
 */
class IssueWorkflowService
{
    public const REVIEW = 'review';
    public const INFO_REQUEST = 'info_request';
    public const ANSWER = 'answer';
    public const INSPECTION = 'inspection';
    public const INSPECTION_RESULT = 'inspection_result';
    public const APPROVAL_REQUEST = 'approval_request';
    public const APPROVAL = 'approval';
    public const SCHEDULE = 'schedule';
    public const MOVE = 'move';
    public const WORK_DONE = 'work_done';
    public const CONFIRMATION = 'confirmation';
    public const CLOSE = 'close';
    public const CANCEL = 'cancel';
    public const JUMP = 'jump';

    /** How a jump reads in the status history. */
    public const JUMP_PREFIX = 'Changed by hand: ';

    public const NEXT_AFTER_NOT_WORKING = 'Schedule more work';

    /** Step numbers for the progress strip, after the owner's flowchart. */
    private const STEPS = [
        'new' => 1,
        'in_review' => 2,
        'waiting_on_store' => 3,
        'inspection' => 5,
        'waiting_approval' => 7,
        'scheduled' => 8,
        'work_done' => 10,
        'closed' => 11,
        'cancelled' => 11,
    ];

    /** Plain words for each action, as a button would say it. */
    private const LABELS = [
        self::REVIEW => 'Start the review',
        self::INFO_REQUEST => 'Ask the store',
        self::ANSWER => "Record the store's answer",
        self::INSPECTION => 'Book an inspection',
        self::INSPECTION_RESULT => 'Record what the inspection found',
        self::APPROVAL_REQUEST => 'Ask for approval',
        self::APPROVAL => 'Record the approval',
        self::SCHEDULE => 'Schedule the work',
        self::MOVE => 'Move a booking',
        self::WORK_DONE => 'Record the work done',
        self::CONFIRMATION => 'Record whether it works now',
        self::CLOSE => 'Close',
        self::CANCEL => 'Cancel',
        self::JUMP => 'Change the status',
    ];

    /** The close checklist, in the order it is shown. */
    private const CHECKLIST = [
        'resolved' => 'The store says it works',
        'cost_recorded' => 'Cost recorded',
        'invoice_attached' => 'Invoice attached',
        'notes_completed' => 'Work notes written',
    ];

    public function __construct(
        private MaintenanceSettingsService $settings,
        private AttachmentService $attachments,
        private NoteService $notes,
    ) {
    }

    // -------------------------------------------------------------- Actions

    /**
     * Review (contract: `review`): new → in_review, setting the target date,
     * the next action and the assigned priority. In any other status that is
     * not finished (in review, waiting on the store, inspection, waiting for
     * approval, scheduled, work done) it only updates them and the status
     * stays where it is -- the plan can change at any point. A key left out
     * is left alone; a key sent as null clears.
     *
     * @param  array{target_completion_at?: string|null, next_action?: string|null, assigned_priority?: string|null}  $data
     */
    public function review(TicketIssue $issue, array $data): TicketIssue
    {
        return DB::transaction(function () use ($issue, $data) {
            $issue = $this->lock($issue);
            $this->assertCan($issue, self::REVIEW);

            foreach (['target_completion_at', 'next_action', 'assigned_priority'] as $key) {
                if (array_key_exists($key, $data)) {
                    $issue->{$key} = $data[$key];
                }
            }
            $issue->save();

            if ($issue->status === IssueStatus::New) {
                TicketStatusService::changeIssueStatus($issue, IssueStatus::InReview);
            }

            return $issue;
        });
    }

    /**
     * The work is done (contract: `work-done`), recorded by the specialist
     * for the technician: what was done, the before/after photos (on the
     * issue), and optionally the warranty that came with it. The issue's
     * pending work tasks on trips are ticked done, as the technician would
     * have from his link.
     *
     * @param  array{work_performed: string, warranty?: array{body: string, expiry_date: string}|null}  $data
     * @param  array<int, UploadedFile>  $files
     */
    public function workDone(TicketIssue $issue, array $data, array $files, User $by): TicketIssue
    {
        return DB::transaction(function () use ($issue, $data, $files, $by) {
            $issue = $this->lock($issue);
            $this->assertCan($issue, self::WORK_DONE);

            $issue->work_performed = $data['work_performed'];
            $issue->next_action = null;
            $issue->save();

            $this->attachments->store($issue, $files);

            if (!empty($data['warranty']['body'] ?? null)) {
                $warranty = new Warranty([
                    'body' => $data['warranty']['body'],
                    'expiry_date' => $data['warranty']['expiry_date'],
                ]);
                $warranty->created_by = $by->id;
                $warranty->save();
                $warranty->ticketIssues()->attach($issue->id);
            }

            TripStopIssue::query()
                ->where('ticket_issue_id', $issue->id)
                ->where('purpose', 'work')
                ->where('task_status', 'pending')
                ->get()
                ->each(fn (TripStopIssue $row) => $row->forceFill(['task_status' => 'done', 'done_at' => now()])->save());

            TicketStatusService::changeIssueStatus($issue, IssueStatus::WorkDone);

            return $issue;
        });
    }

    /**
     * The store says whether it works now (contract: `confirmation`), by
     * itself or through the specialist (`byName`: who said so, optional). Not working sends the issue
     * back to review with "Schedule more work".
     */
    public function confirm(TicketIssue $issue, bool $working, ?string $note, ?string $byName, User $by): TicketIssue
    {
        return DB::transaction(function () use ($issue, $working, $note, $byName, $by) {
            $issue = $this->lock($issue);
            $this->assertCan($issue, self::CONFIRMATION);

            $issue->forceFill([
                'store_confirmed_at' => now(),
                'store_confirmed_working' => $working,
                'store_confirmed_by_name' => InfoRequestService::name($byName),
                'store_confirmed_by' => $by->id,
            ]);

            $note = trim((string) $note);
            if ($note !== '') {
                $this->notes->store($issue, $note, null, []);
            }

            if ($working) {
                $issue->next_action = null;
                $issue->save();

                return $issue;
            }

            $issue->next_action = self::NEXT_AFTER_NOT_WORKING;
            $issue->save();

            $who = 'The store says'.($issue->store_confirmed_by_name ? " ({$issue->store_confirmed_by_name})" : '');
            TicketStatusService::changeIssueStatus(
                $issue,
                IssueStatus::InReview,
                "{$who} it still does not work".($note !== '' ? ': '.Str::limit($note, 200) : '.'),
            );

            return $issue;
        });
    }

    /**
     * Close the issue (contract: `close`) with how it ended. The checklist
     * must be complete, or an override_reason given, which is kept in the
     * status history with what was missing. The final note and "what we
     * learned" are typed notes on the issue (FinalNoteType), like the
     * ticket's final notes.
     *
     * @param  array{resolution: string, final_note?: string|null, what_we_learned?: string|null, override_reason?: string|null}  $data
     */
    public function close(TicketIssue $issue, array $data): TicketIssue
    {
        return DB::transaction(function () use ($issue, $data) {
            $issue = $this->lock($issue);
            $this->assertCan($issue, self::CLOSE);

            $resolution = Resolution::from($data['resolution']);
            $missing = $this->missing($issue, $resolution);
            $override = trim((string) ($data['override_reason'] ?? ''));

            if ($missing !== [] && $override === '') {
                $this->refuse(
                    'Not done yet: '.implode(', ', array_map(fn (array $m) => strtolower($m['label']), $missing))
                        .'. Finish these first, or give a reason to close anyway.',
                    'status',
                    ['checklist' => array_column($missing, 'key')],
                );
            }

            $issue->resolution = $resolution;
            $issue->next_action = null;
            $issue->save();

            foreach (['final_note' => FinalNoteType::FinalNotes, 'what_we_learned' => FinalNoteType::WhatWeLearned] as $key => $type) {
                $body = trim((string) ($data[$key] ?? ''));
                if ($body !== '') {
                    $this->notes->store($issue, $body, $type->value, []);
                }
            }

            $reason = $missing === []
                ? null
                : 'Closed without: '.implode(', ', array_column($missing, 'label')).'. Reason: '.$override;

            TicketStatusService::changeIssueStatus($issue, IssueStatus::Closed, $reason);

            return $issue;
        });
    }

    /**
     * The escape hatch (contract: `jump`): any status to any status, with a
     * reason, logged as a status change made by hand.
     */
    public function jump(TicketIssue $issue, IssueStatus $to, string $reason): TicketIssue
    {
        return DB::transaction(function () use ($issue, $to, $reason) {
            $issue = $this->lock($issue);

            if ($issue->status === $to) {
                $this->refuse('The issue is already '.strtolower($to->label()).'.');
            }

            TicketStatusService::changeIssueStatus($issue, $to, self::JUMP_PREFIX.$reason);

            return $issue;
        });
    }

    /**
     * Cancel one issue (the existing `cancel` endpoint). A finished issue
     * cannot be cancelled; reopen it with jump first.
     */
    public function cancel(TicketIssue $issue, string $reason): TicketIssue
    {
        return DB::transaction(function () use ($issue, $reason) {
            $issue = $this->lock($issue);
            $this->assertCan($issue, self::CANCEL);

            TicketStatusService::changeIssueStatus($issue, IssueStatus::Cancelled, $reason);

            return $issue;
        });
    }

    /**
     * The issue row, locked for the rest of the transaction, so two people
     * acting on the same issue at once cannot both pass the status check.
     * The model keeps its loaded relations; its attributes are refreshed.
     */
    public function lock(TicketIssue $issue): TicketIssue
    {
        $locked = TicketIssue::query()->whereKey($issue->id)->lockForUpdate()->firstOrFail();
        $issue->setRawAttributes($locked->getAttributes(), true);

        return $issue;
    }

    // -------------------------------------------------------------- Machine

    /**
     * The statuses each action may run in. `jump` is not listed: it runs in
     * every status.
     *
     * @return array<int, IssueStatus>
     */
    public function allowedStatuses(string $action): array
    {
        $open = array_values(array_filter(IssueStatus::cases(), fn (IssueStatus $s) => !$s->isTerminal()));

        return match ($action) {
            // Updating the plan never moves the status (except new → in_review).
            self::REVIEW => $open,
            self::INFO_REQUEST => [IssueStatus::New, IssueStatus::InReview, IssueStatus::WaitingOnStore],
            self::INSPECTION => [IssueStatus::New, IssueStatus::InReview, IssueStatus::Inspection],
            self::APPROVAL_REQUEST => [IssueStatus::New, IssueStatus::InReview, IssueStatus::Inspection, IssueStatus::WaitingApproval],
            self::APPROVAL => [IssueStatus::InReview, IssueStatus::Inspection, IssueStatus::WaitingApproval],
            self::SCHEDULE => [IssueStatus::New, IssueStatus::InReview, IssueStatus::Inspection, IssueStatus::Scheduled],
            self::WORK_DONE => [IssueStatus::Inspection, IssueStatus::Scheduled],
            self::CONFIRMATION => [IssueStatus::WorkDone],
            // An answer, a result or a move can arrive whatever the issue is
            // doing by then; only a finished issue refuses them.
            self::ANSWER, self::INSPECTION_RESULT, self::MOVE, self::CLOSE, self::CANCEL => $open,
            self::JUMP => IssueStatus::cases(),
            default => [],
        };
    }

    public function allows(string $action, IssueStatus $status): bool
    {
        return in_array($status, $this->allowedStatuses($action), true);
    }

    /**
     * Refuse the action, with a reason, when the issue's status does not fit.
     *
     * @throws ValidationException
     */
    public function assertCan(TicketIssue $issue, string $action): void
    {
        if (!$this->allows($action, $issue->status)) {
            $this->refuse($this->refusal($action, $issue->status));
        }
    }

    /**
     * @param  array<string, string|array<int, string>>  $extra
     *
     * @throws ValidationException
     */
    public function refuse(string $reason, string $field = 'status', array $extra = []): never
    {
        throw ValidationException::withMessages([$field => $reason] + $extra);
    }

    /**
     * Why the action cannot run now, in plain words.
     */
    public function refusal(string $action, IssueStatus $status): string
    {
        $label = strtolower($status->label());

        if ($status->isTerminal()) {
            return "This issue is {$label}. Change its status first if it needs more work.";
        }

        if ($status === IssueStatus::WaitingOnStore && in_array($action, [self::INSPECTION, self::SCHEDULE, self::APPROVAL_REQUEST, self::APPROVAL], true)) {
            return "We are still waiting for the store to answer. Record the store's answer first.";
        }

        if ($status === IssueStatus::WaitingApproval && in_array($action, [self::SCHEDULE, self::INSPECTION, self::INFO_REQUEST, self::WORK_DONE], true)) {
            return 'The quote is still waiting for approval. Record the approval first.';
        }

        return match ($action) {
            self::INFO_REQUEST => "You can ask the store a question while reviewing the issue, not while it is {$label}.",
            self::INSPECTION => "An inspection can be booked while reviewing the issue, not while it is {$label}.",
            self::APPROVAL_REQUEST => "Approval can be asked for while reviewing or inspecting the issue, not while it is {$label}.",
            self::APPROVAL => "There is no quote to decide on while the issue is {$label}.",
            self::SCHEDULE => "The work can be scheduled while reviewing or inspecting the issue, not while it is {$label}.",
            self::WORK_DONE => "Work can be recorded as done once it is scheduled; this issue is {$label}.",
            self::CONFIRMATION => "The store can say whether it works once the work is done; this issue is {$label}.",
            default => "That cannot be done while the issue is {$label}.",
        };
    }

    public function stepOf(IssueStatus $status): int
    {
        return self::STEPS[$status->value];
    }

    public function label(string $action): string
    {
        return self::LABELS[$action] ?? $action;
    }

    // ----------------------------------------------------------------- Next

    /**
     * The actions that make sense now, most useful first (the first one is
     * the primary button). Actions that need something to act on (an open
     * question, a booked inspection, a live booking) only appear when it is
     * there.
     *
     * @return array<int, array{action: string, label: string, primary: bool}>
     */
    public function next(TicketIssue $issue): array
    {
        $facts = $this->facts($issue);

        $order = match ($issue->status) {
            IssueStatus::New => [self::REVIEW, self::INFO_REQUEST, self::INSPECTION, self::SCHEDULE, self::APPROVAL_REQUEST, self::CLOSE, self::CANCEL],
            IssueStatus::InReview => $facts['last_approval_rejected']
                ? [self::INSPECTION, self::APPROVAL_REQUEST, self::SCHEDULE, self::INFO_REQUEST, self::APPROVAL, self::REVIEW, self::CLOSE, self::CANCEL]
                : [self::SCHEDULE, self::INSPECTION, self::INFO_REQUEST, self::APPROVAL_REQUEST, self::APPROVAL, self::REVIEW, self::CLOSE, self::CANCEL],
            IssueStatus::WaitingOnStore => [self::ANSWER, self::INFO_REQUEST, self::CANCEL],
            IssueStatus::Inspection => $facts['scheduled_inspection']
                ? [self::INSPECTION_RESULT, self::APPROVAL_REQUEST, self::SCHEDULE, self::INSPECTION, self::APPROVAL, self::MOVE, self::WORK_DONE, self::CLOSE, self::CANCEL]
                : [self::APPROVAL_REQUEST, self::SCHEDULE, self::APPROVAL, self::INSPECTION, self::MOVE, self::WORK_DONE, self::CLOSE, self::CANCEL],
            IssueStatus::WaitingApproval => [self::APPROVAL, self::APPROVAL_REQUEST, self::CANCEL],
            IssueStatus::Scheduled => [self::WORK_DONE, self::MOVE, self::SCHEDULE, self::CLOSE, self::CANCEL],
            IssueStatus::WorkDone => $facts['confirmed_working']
                ? [self::CLOSE, self::CONFIRMATION, self::CANCEL]
                : [self::CONFIRMATION, self::CLOSE, self::CANCEL],
            IssueStatus::Closed, IssueStatus::Cancelled => [],
        };
        $order[] = self::JUMP;

        $needs = [
            self::ANSWER => $facts['open_info_request'],
            self::INSPECTION_RESULT => $facts['scheduled_inspection'],
            self::MOVE => $facts['live_schedule'],
        ];

        $next = [];
        foreach ($order as $action) {
            if (!$this->allows($action, $issue->status) || (($needs[$action] ?? true) === false)) {
                continue;
            }

            $label = $action === self::REVIEW && $issue->status !== IssueStatus::New
                ? 'Update the review'
                : $this->label($action);

            $next[] = ['action' => $action, 'label' => $label, 'primary' => $next === []];
        }

        return $next;
    }

    /**
     * What next() needs to know, from loaded relations when they are there
     * (the full read loads them all) and from the database otherwise.
     *
     * @return array{open_info_request: bool, scheduled_inspection: bool, live_schedule: bool, confirmed_working: bool, last_approval_rejected: bool}
     */
    private function facts(TicketIssue $issue): array
    {
        $requests = $issue->relationLoaded('infoRequests') ? $issue->infoRequests : $issue->infoRequests()->get();
        $inspections = $issue->relationLoaded('inspections') ? $issue->inspections : $issue->inspections()->get();
        $assignments = $issue->relationLoaded('assignments') ? $issue->assignments : $issue->assignments()->get();
        $approvals = $issue->relationLoaded('approvals') ? $issue->approvals : $issue->approvals()->get();

        $lastApproval = $approvals->where('mistaken', false)->sortBy('id')->last();

        return [
            'open_info_request' => $requests->contains(fn (IssueInfoRequest $r) => !$r->mistaken && $r->answered_at === null),
            'scheduled_inspection' => $inspections->contains(fn (IssueInspection $i) => !$i->mistaken && $i->status === InspectionStatus::Scheduled),
            'live_schedule' => $assignments->contains(fn (Assignment $a) => !$a->mistaken && $a->ended_at === null),
            'confirmed_working' => $issue->store_confirmed_working === true,
            'last_approval_rejected' => $lastApproval !== null && $lastApproval->decision?->value === 'rejected',
        ];
    }

    // ------------------------------------------------------------ Checklist

    /**
     * The close checklist. A resolution that involved no paid work
     * (fixed by troubleshooting, not needed) ticks everything but the
     * store's confirmation by itself -- and that one too, since there was
     * nothing to confirm.
     *
     * Reads the loaded paymentLines.payment.attachments when present (the
     * full read), the database otherwise (the close action).
     *
     * @return array<int, array{key: string, label: string, done: bool}>
     */
    public function checklist(TicketIssue $issue, ?Resolution $resolution = null): array
    {
        $resolution ??= $issue->resolution;
        $exempt = $resolution?->exemptsChecklist() ?? false;

        [$hasCost, $hasInvoice] = $this->paymentFacts($issue);
        // An inspection fee typed at booking is a recorded cost too.
        $hasCost = $hasCost || $issue->inspections()->where('mistaken', false)->whereNotNull('inspection_fee')->exists();

        $done = [
            'resolved' => $exempt || $issue->store_confirmed_working === true,
            'cost_recorded' => $exempt || $hasCost,
            'invoice_attached' => $exempt || $hasInvoice,
            'notes_completed' => $exempt || trim((string) $issue->work_performed) !== '',
        ];

        $list = [];
        foreach (self::CHECKLIST as $key => $label) {
            $list[] = ['key' => $key, 'label' => $label, 'done' => $done[$key]];
        }

        return $list;
    }

    /**
     * @return array<int, array{key: string, label: string, done: bool}>
     */
    public function missing(TicketIssue $issue, ?Resolution $resolution): array
    {
        return array_values(array_filter($this->checklist($issue, $resolution), fn (array $item) => !$item['done']));
    }

    /**
     * Whether a non-mistaken payment line is linked to the issue, and
     * whether one of those payments carries a file (the invoice).
     *
     * @return array{0: bool, 1: bool}
     */
    private function paymentFacts(TicketIssue $issue): array
    {
        if ($issue->relationLoaded('paymentLines')) {
            $live = $issue->paymentLines->filter(fn (PaymentLine $l) => $l->payment !== null && !$l->payment->mistaken);

            return [
                $live->isNotEmpty(),
                $live->contains(fn (PaymentLine $l) => $l->payment->relationLoaded('attachments')
                    ? $l->payment->attachments->isNotEmpty()
                    : $l->payment->attachments()->exists()),
            ];
        }

        $payments = Payment::query()
            ->where('mistaken', false)
            ->whereHas('lines.ticketIssues', fn ($q) => $q->where('ticket_issues.id', $issue->id));

        return [
            (clone $payments)->exists(),
            (clone $payments)->whereHas('attachments')->exists(),
        ];
    }

    // ------------------------------------------------------------ Approvals

    /**
     * Who may approve this amount, from maintenance_settings.approval_levels.
     *
     * @return array{amount: string, level: string, label: string}
     */
    public function approvalLevelFor(string|int|float $amount): array
    {
        return $this->settings->levelFor($amount);
    }
}
