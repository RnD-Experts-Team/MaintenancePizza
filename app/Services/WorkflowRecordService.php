<?php

namespace App\Services;

use App\Models\AttendanceEntry;
use App\Models\DailyPayPayment;
use App\Models\Diagnosis;
use App\Models\PartUsage;
use App\Models\PayEntry;
use App\Models\Warranty;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Creates and presents the per-issue workflow records that attach to one-or-many
 * issues (diagnosis, attendance, part usage, pay/driving, warranty), plus the
 * "mark mistaken" toggles. Files are persisted through AttachmentService.
 */
class WorkflowRecordService
{
    private const CLOCKS = [
        'start_clock',
        'end_clock',
        'start_break',
        'end_break',
        'start_parts_run',
        'end_parts_run',
        'start_travel',
        'end_travel',
    ];

    private const PAY_FIELDS = [
        'base_pay',
        'performance_pay',
        'driving_time',
        'miles_driven',
        'per_mile_rate',
        'driving_base_pay',
        'driving_performance_pay',
    ];

    public function __construct(
        private AttachmentService $attachments,
        private CatalogService $catalog,
        private NoteService $notes,
        private StockService $stock,
        private StorageLocationService $storageLocations,
    ) {
    }

    /**
     * Eager loads for a record's notes (with their files + author) and files.
     *
     * @var list<string>
     */
    private const NOTE_LOADS = ['creator', 'attachments.creator', 'notes.creator', 'notes.attachments.creator'];

    /**
     * Everything presentAttendance() reads, beyond the technician and issues.
     * The claims are what the payment status is derived from.
     *
     * @var list<string>
     */
    private const ATTENDANCE_LOADS = [
        'dailyPayPayments.entry',
        'dailyPayPayments.technician',
        ...self::NOTE_LOADS,
    ];

    /**
     * Everything presentPartUsage() reads, beyond the ticket issues.
     *
     * @var list<string>
     */
    private const PART_USAGE_LOADS = [
        'part',
        'dailyPayPayments.entry',
        'dailyPayPayments.technician',
        'paidByTechnician',
        'storageLocation',
        'returnedToStorageLocation',
        'stockMovements',
        ...self::NOTE_LOADS,
    ];

    // ---------------------------------------------------------------- Diagnosis

    /**
     * @param  array<int>  $ticketIssueIds
     * @param  array<int, UploadedFile>  $files
     * @return array<string, mixed>
     */
    public function createDiagnosis(array $ticketIssueIds, ?string $body, array $files): array
    {
        $diagnosis = DB::transaction(function () use ($ticketIssueIds, $body, $files) {
            $diagnosis = new Diagnosis(['body' => $body]);
            $diagnosis->created_by = Auth::id();
            $diagnosis->save();

            $diagnosis->ticketIssues()->attach($ticketIssueIds);
            $this->attachments->store($diagnosis, $files);

            return $diagnosis;
        });

        return $this->presentDiagnosis($diagnosis->load(['ticketIssues', ...self::NOTE_LOADS]));
    }

    /**
     * @return array<string, mixed>
     */
    public function markDiagnosisMistaken(Diagnosis $diagnosis): array
    {
        $diagnosis->update(['mistaken' => true]);

        return $this->presentDiagnosis($diagnosis->load(self::NOTE_LOADS));
    }

    // --------------------------------------------------------------- Attendance

    /**
     * The issues may belong to more than one ticket: a technician drives out
     * once and works several tickets at the same store.
     *
     * @param  array<string, mixed>  $data  Includes technician_id, ticket_issue_ids, clock fields and optional notes.
     * @param  array<int, UploadedFile>  $files
     * @param  array<int, array<int, UploadedFile>>  $noteFiles  noteIndex → files
     * @return array<string, mixed>
     */
    public function createAttendance(array $data, array $files, array $noteFiles = []): array
    {
        $entry = DB::transaction(function () use ($data, $files, $noteFiles) {
            $entry = new AttendanceEntry(array_merge(
                ['technician_id' => $data['technician_id']],
                array_intersect_key($data, array_flip(self::CLOCKS)),
            ));
            $entry->created_by = Auth::id();
            $entry->save();

            $entry->ticketIssues()->attach($data['ticket_issue_ids']);
            $this->attachments->store($entry, $files);

            foreach ($data['notes'] ?? [] as $i => $note) {
                $this->notes->store($entry, $note['body'], $note['type'] ?? null, $noteFiles[$i] ?? []);
            }

            return $entry;
        });

        return $this->presentAttendance($entry->load(['technician', 'ticketIssues', ...self::ATTENDANCE_LOADS]));
    }

    /**
     * @return array<string, mixed>
     */
    public function markAttendanceMistaken(AttendanceEntry $entry): array
    {
        $entry->update(['mistaken' => true]);

        return $this->presentAttendance($entry->load(['technician', ...self::ATTENDANCE_LOADS]));
    }

    // -------------------------------------------------------------- Part usage

    /**
     * $data carries ticket_issue_ids, part_id, quantity, unit_cost, source,
     * paid_by and the optional storage/return fields.
     *
     * `cost` is computed here, once, as quantity * unit_cost, and is the GROSS
     * outlay — TicketService's part_cost_* filters sum this column, so it must
     * never become net-of-returns. Round once at write; never re-derive on
     * read, or a presenter will disagree with the stored value.
     *
     * Drawing from storage moves stock inside this same transaction, so a draw
     * with nothing on the shelf rolls the part usage back with it: the caller
     * gets a 422 and no row is created.
     *
     * @param  array<string, mixed>  $data
     * @param  array<int, UploadedFile>  $files
     * @param  array<int, array<int, UploadedFile>>  $noteFiles  noteIndex → files
     * @return array<string, mixed>
     */
    public function createPartUsage(array $data, array $files, array $noteFiles = []): array
    {
        $usage = DB::transaction(function () use ($data, $files, $noteFiles) {
            $quantity = (string) $data['quantity'];
            $unitCost = (string) $data['unit_cost'];

            $usage = new PartUsage([
                'part_id' => $data['part_id'],
                'quantity' => $quantity,
                'unit_cost' => $unitCost,
                'cost' => bcmul($quantity, $unitCost, 2),
                'source' => $data['source'],
                'paid_by' => $data['paid_by'],
                'paid_by_technician_id' => $data['paid_by_technician_id'] ?? null,
                'storage_location_id' => $data['storage_location_id'] ?? null,
                'returned_quantity' => $data['returned_quantity'] ?? 0,
                'returned_to_storage_location_id' => $data['returned_to_storage_location_id'] ?? null,
            ]);
            $usage->created_by = Auth::id();
            $usage->save();

            $usage->ticketIssues()->attach($data['ticket_issue_ids']);
            $this->attachments->store($usage, $files);

            foreach ($data['notes'] ?? [] as $i => $note) {
                $this->notes->store($usage, $note['body'], $note['type'] ?? null, $noteFiles[$i] ?? []);
            }

            $this->stock->drawForPartUsage($usage);
            $this->stock->returnForPartUsage($usage);

            return $usage;
        });

        return $this->presentPartUsage($usage->load([...self::PART_USAGE_LOADS, 'ticketIssues']));
    }

    /**
     * Flagging a usage as a mistake puts any stock it moved back — by writing
     * the equal-and-opposite movements, never by editing or deleting the
     * originals. The ledger is append-only.
     *
     * @return array<string, mixed>
     */
    public function markPartUsageMistaken(PartUsage $usage): array
    {
        DB::transaction(function () use ($usage) {
            $usage->update(['mistaken' => true]);
            $this->stock->reverseForPartUsage($usage);
        });

        return $this->presentPartUsage($usage->load(self::PART_USAGE_LOADS));
    }

    // --------------------------------------------------------------- Pay entry

    /**
     * @param  array<string, mixed>  $data  Includes technician_id, ticket_issue_ids and money fields.
     * @return array<string, mixed>
     */
    public function createPayEntry(array $data): array
    {
        $entry = DB::transaction(function () use ($data) {
            $entry = new PayEntry(array_merge(
                ['technician_id' => $data['technician_id']],
                array_intersect_key($data, array_flip(self::PAY_FIELDS)),
            ));
            $entry->created_by = Auth::id();
            $entry->save();

            $entry->ticketIssues()->attach($data['ticket_issue_ids']);

            return $entry;
        });

        return $this->presentPayEntry($entry->load(['technician', 'ticketIssues', ...self::NOTE_LOADS]));
    }

    /**
     * @return array<string, mixed>
     */
    public function markPayEntryMistaken(PayEntry $entry): array
    {
        $entry->update(['mistaken' => true]);

        return $this->presentPayEntry($entry->load(['technician', ...self::NOTE_LOADS]));
    }

    // ---------------------------------------------------------------- Warranty

    /**
     * @param  array<int>  $ticketIssueIds
     * @param  array<int, UploadedFile>  $files
     * @return array<string, mixed>
     */
    public function createWarranty(array $ticketIssueIds, string $body, string $expiryDate, array $files): array
    {
        $warranty = DB::transaction(function () use ($ticketIssueIds, $body, $expiryDate, $files) {
            $warranty = new Warranty(['body' => $body, 'expiry_date' => $expiryDate]);
            $warranty->created_by = Auth::id();
            $warranty->save();

            $warranty->ticketIssues()->attach($ticketIssueIds);
            $this->attachments->store($warranty, $files);

            return $warranty;
        });

        return $this->presentWarranty($warranty->load(['ticketIssues', ...self::NOTE_LOADS]));
    }

    /**
     * @return array<string, mixed>
     */
    public function markWarrantyMistaken(Warranty $warranty): array
    {
        $warranty->update(['mistaken' => true]);

        return $this->presentWarranty($warranty->load(['ticketIssues', ...self::NOTE_LOADS]));
    }

    // ------------------------------------------------------------- Presenters

    /**
     * @return array<string, mixed>
     */
    public function presentDiagnosis(Diagnosis $diagnosis): array
    {
        return [
            'id' => $diagnosis->id,
            'body' => $diagnosis->body,
            'mistaken' => $diagnosis->mistaken,
            'attachments' => $this->presentAttachments($diagnosis),
            'notes' => $this->notes->presentMany($diagnosis),
            'ticket_issue_ids' => $this->ticketIssueIds($diagnosis),
            'created_by' => $diagnosis->created_by,
            'creator' => $diagnosis->relationLoaded('creator') && $diagnosis->creator
                ? ['id' => $diagnosis->creator->id, 'name' => $diagnosis->creator->name, 'email' => $diagnosis->creator->email]
                : null,
            'created_at' => $diagnosis->created_at,
            'updated_at' => $diagnosis->updated_at,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function presentAttendance(AttendanceEntry $entry): array
    {
        return [
            'id' => $entry->id,
            'technician_id' => $entry->technician_id,
            'technician' => $entry->relationLoaded('technician') && $entry->technician
                ? $this->catalog->presentTechnician($entry->technician)
                : null,
            'start_clock' => $entry->start_clock,
            'end_clock' => $entry->end_clock,
            'start_break' => $entry->start_break,
            'end_break' => $entry->end_break,
            'start_parts_run' => $entry->start_parts_run,
            'end_parts_run' => $entry->end_parts_run,
            'start_travel' => $entry->start_travel,
            'end_travel' => $entry->end_travel,
            // Derived, never stored. Minutes are authoritative; hours are the
            // same figure rounded once for display.
            'durations' => $this->presentDurations($entry),
            // Whether these hours have been settled through a pay sheet.
            'payment' => $this->presentPaymentStatus($entry),
            'mistaken' => $entry->mistaken,
            'attachments' => $this->presentAttachments($entry),
            'notes' => $this->notes->presentMany($entry),
            'ticket_issue_ids' => $this->ticketIssueIds($entry),
            'created_by' => $entry->created_by,
            'creator' => $entry->relationLoaded('creator') && $entry->creator
                ? ['id' => $entry->creator->id, 'name' => $entry->creator->name, 'email' => $entry->creator->email]
                : null,
            'created_at' => $entry->created_at,
            'updated_at' => $entry->updated_at,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function presentPartUsage(PartUsage $usage): array
    {
        return [
            'id' => $usage->id,
            'part_id' => $usage->part_id,
            'part' => $usage->relationLoaded('part') && $usage->part
                ? $this->catalog->presentPart($usage->part)
                : null,
            'quantity' => $usage->quantity,
            'unit_cost' => $usage->unit_cost,
            // GROSS outlay (quantity * unit_cost). net_cost is what the payer
            // is actually out of pocket once returns are taken off; only that
            // one is reimbursed.
            'cost' => $usage->cost,
            'net_quantity' => $usage->netQuantity(),
            'net_cost' => $usage->netCost(),
            'source' => [
                'value' => $usage->source->value,
                'label' => $usage->source->label(),
            ],
            'paid_by' => [
                'value' => $usage->paid_by->value,
                'label' => $usage->paid_by->label(),
            ],
            'reimbursable' => $usage->isReimbursable(),
            'paid_by_technician_id' => $usage->paid_by_technician_id,
            'paid_by_technician' => $usage->relationLoaded('paidByTechnician') && $usage->paidByTechnician
                ? $this->catalog->presentTechnician($usage->paidByTechnician)
                : null,
            'storage_location_id' => $usage->storage_location_id,
            'storage_location' => $usage->relationLoaded('storageLocation') && $usage->storageLocation
                ? $this->storageLocations->present($usage->storageLocation)
                : null,
            'returned_quantity' => $usage->returned_quantity,
            'returned_to_storage_location_id' => $usage->returned_to_storage_location_id,
            'returned_to_storage_location' => $usage->relationLoaded('returnedToStorageLocation') && $usage->returnedToStorageLocation
                ? $this->storageLocations->present($usage->returnedToStorageLocation)
                : null,
            'stock_movement_ids' => $usage->relationLoaded('stockMovements')
                ? $usage->stockMovements->pluck('id')->all()
                : null,
            // Whether whoever paid has had it back through a pay sheet.
            'payment' => $this->presentPaymentStatus($usage),
            'mistaken' => $usage->mistaken,
            'attachments' => $this->presentAttachments($usage),
            'notes' => $this->notes->presentMany($usage),
            'ticket_issue_ids' => $this->ticketIssueIds($usage),
            'created_by' => $usage->created_by,
            'creator' => $usage->relationLoaded('creator') && $usage->creator
                ? ['id' => $usage->creator->id, 'name' => $usage->creator->name, 'email' => $usage->creator->email]
                : null,
            'created_at' => $usage->created_at,
            'updated_at' => $usage->updated_at,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function presentPayEntry(PayEntry $entry): array
    {
        return [
            'id' => $entry->id,
            'technician_id' => $entry->technician_id,
            'technician' => $entry->relationLoaded('technician') && $entry->technician
                ? $this->catalog->presentTechnician($entry->technician)
                : null,
            'base_pay' => $entry->base_pay,
            'performance_pay' => $entry->performance_pay,
            'driving_time' => $entry->driving_time,
            'miles_driven' => $entry->miles_driven,
            'per_mile_rate' => $entry->per_mile_rate,
            'driving_base_pay' => $entry->driving_base_pay,
            'driving_performance_pay' => $entry->driving_performance_pay,
            'mistaken' => $entry->mistaken,
            'attachments' => $this->presentAttachments($entry),
            'notes' => $this->notes->presentMany($entry),
            'ticket_issue_ids' => $this->ticketIssueIds($entry),
            'created_by' => $entry->created_by,
            'creator' => $entry->relationLoaded('creator') && $entry->creator
                ? ['id' => $entry->creator->id, 'name' => $entry->creator->name, 'email' => $entry->creator->email]
                : null,
            'created_at' => $entry->created_at,
            'updated_at' => $entry->updated_at,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function presentWarranty(Warranty $warranty): array
    {
        return [
            'id' => $warranty->id,
            'body' => $warranty->body,
            'expiry_date' => $warranty->expiry_date?->toDateString(),
            'mistaken' => $warranty->mistaken,
            'attachments' => $this->presentAttachments($warranty),
            'notes' => $this->notes->presentMany($warranty),
            'ticket_issue_ids' => $this->ticketIssueIds($warranty),
            'created_by' => $warranty->created_by,
            'creator' => $warranty->relationLoaded('creator') && $warranty->creator
                ? ['id' => $warranty->creator->id, 'name' => $warranty->creator->name, 'email' => $warranty->creator->email]
                : null,
            'created_at' => $warranty->created_at,
            'updated_at' => $warranty->updated_at,
        ];
    }

    /**
     * Whether this record has been settled through a pay sheet, and which
     * payments did it. Being on a sheet IS being paid.
     *
     * Derived from the pay sheets themselves, so it can never disagree with
     * them. Null when the claims are not loaded, like every other relation.
     *
     * @param  AttendanceEntry|PartUsage  $record
     * @return array<string, mixed>|null
     */
    private function presentPaymentStatus($record): ?array
    {
        $status = $record->paymentStatus();

        if ($status === null) {
            return null;
        }

        $payments = $record->relationLoaded('dailyPayPayments')
            ? $record->dailyPayPayments->map(function (DailyPayPayment $payment) use ($record) {
                $pivot = $payment->pivot;

                $presented = [
                    'daily_pay_payment_id' => $payment->id,
                    'daily_pay_entry_id' => $payment->daily_pay_entry_id,
                    'daily_pay_line_id' => $pivot->daily_pay_line_id,
                    'date' => $payment->relationLoaded('entry') && $payment->entry
                        ? $payment->entry->date->toDateString()
                        : null,
                    'technician_id' => $payment->technician_id,
                    'technician' => $payment->relationLoaded('technician') && $payment->technician
                        ? $this->catalog->presentTechnician($payment->technician)
                        : null,
                ];

                // A reimbursed receipt carries the money it was allowed; hours
                // carry the minutes that were counted.
                return $record instanceof PartUsage
                    // Pivot columns carry no casts, so the money is formatted
                    // here to match every other decimal the API emits.
                    ? $presented + ['amount' => number_format((float) $pivot->amount, 2, '.', '')]
                    : $presented + ['minutes' => [
                        'work' => (int) $pivot->work_minutes,
                        'travel' => (int) $pivot->travel_minutes,
                        'break' => (int) $pivot->break_minutes,
                        'parts_run' => (int) $pivot->parts_run_minutes,
                    ]];
            })->all()
            : [];

        return [
            'status' => ['value' => $status->value, 'label' => $status->label()],
            'payments' => $payments,
        ];
    }

    /**
     * Both units of each attendance bucket, plus whatever durations() could
     * not make sense of. Nothing here is stored — see AttendanceEntry::durations().
     *
     * @return array<string, mixed>
     */
    private function presentDurations(AttendanceEntry $entry): array
    {
        $d = $entry->durations();
        $warnings = $d['warnings'];
        unset($d['warnings']);

        return [
            'minutes' => $d,
            'hours' => array_map(fn(int $m) => round($m / 60, 2), $d),
            'warnings' => $warnings,
        ];
    }

    /**
     * @param  Model  $model
     * @return array<int, array<string, mixed>>|null
     */
    private function presentAttachments($model): ?array
    {
        if (!$model->relationLoaded('attachments')) {
            return null;
        }

        return $model->attachments->map(fn($a) => $this->attachments->present($a))->all();
    }

    /**
     * @param  Model  $model
     * @return array<int>|null
     */
    private function ticketIssueIds($model): ?array
    {
        return $model->relationLoaded('ticketIssues')
            ? $model->ticketIssues->pluck('id')->all()
            : null;
    }
}
