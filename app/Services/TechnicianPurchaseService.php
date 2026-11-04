<?php

namespace App\Services;

use App\Models\Supplier;
use App\Models\TechnicianPurchase;
use App\Models\Trip;
use App\Models\TripStopIssue;
use App\Models\User;
use App\Support\NameKey;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Parts a technician bought with his own money (docs/MAINTENANCE.md §3/§4),
 * from his trip link or typed in by the office, waiting to be paid back by a
 * parts payment line. The receipts are the purchase's attachments.
 *
 * PAID means claimed by a payment line whose payment is not mistaken; a
 * mistaken payment releases its purchases even if the payments side has not
 * cleared `payment_line_id` yet.
 */
class TechnicianPurchaseService
{
    /** @var list<string> */
    public const WITH = [
        'technician',
        'ticketIssue.issue',
        'ticketIssue.ticket.store',
        'part',
        'supplier',
        'paymentLine.payment',
        'attachments.creator',
    ];

    public function __construct(private AttachmentService $attachments, private NoteService $notes) {}

    /**
     * @param  array{technician_id?: int|string|null, unpaid?: mixed, trip_id?: int|string|null, ticket_issue_id?: int|string|null}  $filters
     * @return Collection<int, TechnicianPurchase>
     */
    public function list(array $filters): Collection
    {
        return TechnicianPurchase::query()
            ->with(self::WITH)
            ->when($filters['technician_id'] ?? null, fn (Builder $q, $id) => $q->where('technician_id', (int) $id))
            ->when($filters['trip_id'] ?? null, fn (Builder $q, $id) => $q->where('trip_id', (int) $id))
            ->when($filters['ticket_issue_id'] ?? null, fn (Builder $q, $id) => $q->where('ticket_issue_id', (int) $id))
            ->when(filter_var($filters['unpaid'] ?? false, FILTER_VALIDATE_BOOL), fn (Builder $q) => $this->whereUnpaid($q))
            ->orderByDesc('bought_at')
            ->orderByDesc('id')
            ->get();
    }

    /**
     * Not mistaken and not claimed by a live payment.
     *
     * @param  Builder<TechnicianPurchase>  $query
     * @return Builder<TechnicianPurchase>
     */
    public function whereUnpaid(Builder $query): Builder
    {
        return $query->where('mistaken', false)->where(fn (Builder $q) => $q
            ->whereNull('payment_line_id')
            ->orWhereHas('paymentLine.payment', fn (Builder $p) => $p->where('mistaken', true)));
    }

    /**
     * Record a purchase. The supplier is given by id, or by name: a name
     * nobody has used yet creates the supplier, one already on the list
     * (ignoring capitals and repeated spaces) is that supplier.
     *
     * @param  array<string, mixed>  $data  description, total_cost, technician_id, trip_id?, trip_stop_id?, part_id?, quantity?, supplier_id?, supplier_name?, ticket_issue_id?, serial_number?
     * @param  array<int, UploadedFile>  $receipts
     */
    public function create(array $data, array $receipts, ?User $by): TechnicianPurchase
    {
        return DB::transaction(function () use ($data, $receipts, $by) {
            $supplierId = isset($data['supplier_id'])
                ? (int) $data['supplier_id']
                : (isset($data['supplier_name']) && NameKey::of($data['supplier_name']) !== ''
                    ? $this->supplierNamed($data['supplier_name'], $by)->id
                    : null);

            $purchase = TechnicianPurchase::query()->create([
                'technician_id' => (int) $data['technician_id'],
                'trip_id' => $data['trip_id'] ?? null,
                'trip_stop_id' => $data['trip_stop_id'] ?? null,
                'ticket_issue_id' => $data['ticket_issue_id'] ?? null,
                'part_id' => $data['part_id'] ?? null,
                'description' => trim((string) $data['description']),
                'quantity' => $data['quantity'] ?? 1,
                'total_cost' => $data['total_cost'],
                'supplier_id' => $supplierId,
                'serial_number' => isset($data['serial_number']) ? trim((string) $data['serial_number']) ?: null : null,
                'bought_at' => $data['bought_at'] ?? now(),
                'created_by' => $by?->id,
            ]);

            $this->attachments->store($purchase, $receipts);

            return $purchase;
        });
    }

    /**
     * The supplier with this name, created when there is none. Soft-deleted
     * suppliers count: the name is still theirs (the unique index covers
     * them), so the purchase points at that supplier rather than failing.
     */
    public function supplierNamed(string $name, ?User $by): Supplier
    {
        $key = NameKey::of($name);
        $existing = Supplier::withTrashed()->where('name_key', $key)->first();

        if ($existing !== null) {
            return $existing;
        }

        try {
            return DB::transaction(fn () => Supplier::query()->create(['name' => $name, 'created_by' => $by?->id]));
        } catch (UniqueConstraintViolationException) {
            // Someone created it a moment ago.
            return Supplier::withTrashed()->where('name_key', $key)->firstOrFail();
        }
    }

    /**
     * The trip stop a purchase on this issue belongs to, when the issue is
     * on one of the trip's (live) stops.
     */
    public function stopIdFor(Trip $trip, ?int $ticketIssueId): ?int
    {
        if ($ticketIssueId === null) {
            return null;
        }

        return TripStopIssue::query()
            ->where('ticket_issue_id', $ticketIssueId)
            ->whereHas('stop', fn (Builder $q) => $q->where('trip_id', $trip->id)->where('mistaken', false))
            ->value('trip_stop_id');
    }

    /**
     * Whether a ticket issue is on one of the trip's live stops.
     */
    public function issueOnTrip(Trip $trip, int $ticketIssueId): bool
    {
        return $this->stopIdFor($trip, $ticketIssueId) !== null;
    }

    public function markMistaken(TechnicianPurchase $purchase, ?string $reason, User $by): TechnicianPurchase
    {
        if ($purchase->mistaken) {
            throw ValidationException::withMessages(['status' => 'This purchase is already marked as a mistake.']);
        }
        if ($this->paymentId($purchase) !== null) {
            throw ValidationException::withMessages(['status' => 'This purchase is already paid back. Edit or mark that payment as a mistake first.']);
        }

        return DB::transaction(function () use ($purchase, $reason) {
            $purchase->mistaken = true;
            $purchase->save();

            $reason = $reason === null ? null : trim($reason);
            if ($reason !== null && $reason !== '') {
                $this->notes->store($purchase, 'Marked as a mistake: '.$reason, 'mistaken', []);
            }

            return $purchase;
        });
    }

    /**
     * The payment that paid the purchase back, or null.
     */
    public function paymentId(TechnicianPurchase $purchase): ?int
    {
        if ($purchase->payment_line_id === null) {
            return null;
        }

        $payment = $purchase->paymentLine?->payment;

        return $payment !== null && ! $payment->mistaken ? (int) $payment->id : null;
    }

    /**
     * @return array<string, mixed>
     */
    public function present(TechnicianPurchase $purchase): array
    {
        $purchase->loadMissing(self::WITH);
        $issue = $purchase->ticketIssue;
        $paymentId = $this->paymentId($purchase);

        return [
            'id' => $purchase->id,
            'technician' => $purchase->technician ? ['id' => $purchase->technician->id, 'name' => $purchase->technician->name] : null,
            'trip_id' => $purchase->trip_id,
            'trip_stop_id' => $purchase->trip_stop_id,
            'ticket_issue' => $issue ? [
                'id' => $issue->id,
                'ticket_id' => $issue->ticket_id,
                'title' => $issue->displayTitle(),
                'store_number' => $issue->ticket?->store?->store_number,
            ] : null,
            'part' => $purchase->part ? ['id' => $purchase->part->id, 'name' => $purchase->part->name] : null,
            'description' => $purchase->description,
            'quantity' => $purchase->quantity,
            'total_cost' => $purchase->total_cost,
            'supplier' => $purchase->supplier ? ['id' => $purchase->supplier->id, 'name' => $purchase->supplier->name] : null,
            'serial_number' => $purchase->serial_number,
            'bought_at' => $purchase->bought_at,
            'paid' => $paymentId !== null,
            'payment_id' => $paymentId,
            'receipts' => $this->attachments->presentMany($purchase),
            'mistaken' => (bool) $purchase->mistaken,
            'created_at' => $purchase->created_at,
        ];
    }
}
