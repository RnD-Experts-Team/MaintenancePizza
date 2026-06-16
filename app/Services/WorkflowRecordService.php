<?php

namespace App\Services;

use App\Models\AttendanceEntry;
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
    ) {
    }

    /**
     * Eager loads for a record's notes (with their files + author) and files.
     *
     * @var list<string>
     */
    private const NOTE_LOADS = ['creator', 'attachments.creator', 'notes.creator', 'notes.attachments.creator'];

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
     * @param  array<string, mixed>  $data  Includes technician_id, ticket_issue_ids, and clock fields.
     * @param  array<int, UploadedFile>  $files
     * @return array<string, mixed>
     */
    public function createAttendance(array $data, array $files): array
    {
        $entry = DB::transaction(function () use ($data, $files) {
            $entry = new AttendanceEntry(array_merge(
                ['technician_id' => $data['technician_id']],
                array_intersect_key($data, array_flip(self::CLOCKS)),
            ));
            $entry->created_by = Auth::id();
            $entry->save();

            $entry->ticketIssues()->attach($data['ticket_issue_ids']);
            $this->attachments->store($entry, $files);

            return $entry;
        });

        return $this->presentAttendance($entry->load(['technician', 'ticketIssues', ...self::NOTE_LOADS]));
    }

    /**
     * @return array<string, mixed>
     */
    public function markAttendanceMistaken(AttendanceEntry $entry): array
    {
        $entry->update(['mistaken' => true]);

        return $this->presentAttendance($entry->load(['technician', ...self::NOTE_LOADS]));
    }

    // -------------------------------------------------------------- Part usage

    /**
     * @param  array<int>  $ticketIssueIds
     * @param  array<int, UploadedFile>  $files
     * @return array<string, mixed>
     */
    public function createPartUsage(array $ticketIssueIds, int $partId, float|string $cost, array $files): array
    {
        $usage = DB::transaction(function () use ($ticketIssueIds, $partId, $cost, $files) {
            $usage = new PartUsage(['part_id' => $partId, 'cost' => $cost]);
            $usage->created_by = Auth::id();
            $usage->save();

            $usage->ticketIssues()->attach($ticketIssueIds);
            $this->attachments->store($usage, $files);

            return $usage;
        });

        return $this->presentPartUsage($usage->load(['part', 'ticketIssues', ...self::NOTE_LOADS]));
    }

    /**
     * @return array<string, mixed>
     */
    public function markPartUsageMistaken(PartUsage $usage): array
    {
        $usage->update(['mistaken' => true]);

        return $this->presentPartUsage($usage->load(['part', ...self::NOTE_LOADS]));
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
            'cost' => $usage->cost,
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
