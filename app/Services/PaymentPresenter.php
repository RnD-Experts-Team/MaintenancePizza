<?php

namespace App\Services;

use App\Enums\PaymentLineType;
use App\Models\Payment;
use App\Models\PaymentLine;
use App\Models\PaymentRevision;
use App\Models\TechnicianPurchase;
use App\Models\TicketIssue;
use App\Models\TripSession;

/**
 * The shapes payments, their lines, unpaid sessions and technician purchases
 * take on the wire (docs/MAINTENANCE-V2.md §4). Pure presentation: every
 * relation it reads is loaded by the caller through the *_LOADS constants.
 */
class PaymentPresenter
{
    /** What a payment row in the list needs. */
    public const ROW_LOADS = [
        'technician.category',
        'supplier',
        'lines.store',
        'attachments',
    ];

    /** Everything the payment page shows. */
    public const SHOW_LOADS = [
        'technician.category',
        'supplier',
        'creator',
        'lines.store',
        'lines.storageLocation',
        'lines.part',
        'lines.boughtFrom',
        'lines.ticketIssues.ticket.store',
        'lines.ticketIssues.issue',
        'lines.tripSessions.events',
        'lines.stockMovement',
        'lines.partUnits',
        'lines.technicianPurchases.technician',
        'lines.technicianPurchases.ticketIssue.ticket.store',
        'lines.technicianPurchases.ticketIssue.issue',
        'lines.technicianPurchases.part',
        'lines.technicianPurchases.supplier',
        'lines.technicianPurchases.attachments.creator',
        'lines.technicianPurchases.paymentLine',
        'notes.creator',
        'notes.attachments.creator',
        'attachments.creator',
        'revisions.editor',
    ];

    /** What an unpaid session needs: its events (durations) and its trip's stops. */
    public const SESSION_LOADS = [
        'events',
        'trip.stops.store',
        'trip.stops.ticketIssues.ticket.store',
        'trip.stops.ticketIssues.issue',
    ];

    public const PURCHASE_LOADS = [
        'technician',
        'ticketIssue.ticket.store',
        'ticketIssue.issue',
        'part',
        'supplier',
        'attachments.creator',
        'paymentLine',
    ];

    public function __construct(
        private TripService $trips,
        private NoteService $notes,
        private AttachmentService $attachments,
        private CatalogService $catalog,
    ) {}

    /**
     * One row of the payments list.
     *
     * @return array<string, mixed>
     */
    public function row(Payment $payment): array
    {
        $lines = $payment->lines;
        $paidTo = $payment->paidTo();

        if ($paidTo !== null) {
            $paidTo['category'] = $paidTo['type'] === 'technician' && $payment->technician?->category
                ? ['id' => $payment->technician->category->id, 'name' => $payment->technician->category->name]
                : null;
        }

        return [
            'id' => $payment->id,
            'paid_at' => $payment->paid_at?->toDateString(),
            'paid_to' => $paidTo,
            'method' => $payment->method?->value,
            'method_label' => $payment->method?->label(),
            'reference' => $payment->reference,
            'invoice_number' => $payment->invoice_number,
            'total' => $this->money($payment->total),
            'line_count' => $lines->count(),
            'stores' => $lines
                ->map(fn (PaymentLine $l) => $l->store?->store_number ?? $l->other_store)
                ->filter()
                ->unique()
                ->sort()
                ->values()
                ->all(),
            'types' => $lines->map(fn (PaymentLine $l) => $l->type->value)->unique()->values()->all(),
            'has_invoice' => $payment->attachments->isNotEmpty(),
            'mistaken' => (bool) $payment->mistaken,
            'created_at' => $payment->created_at,
            'updated_at' => $payment->updated_at,
        ];
    }

    /**
     * The payment page: the row plus its lines, notes, files and revisions.
     *
     * @return array<string, mixed>
     */
    public function show(Payment $payment): array
    {
        return $this->row($payment) + [
            'technician_id' => $payment->technician_id,
            'supplier_id' => $payment->supplier_id,
            'lines' => $payment->lines->map(fn (PaymentLine $l) => $this->line($l))->values()->all(),
            'notes' => $this->notes->presentMany($payment),
            'attachments' => $this->attachments->presentMany($payment),
            'revisions' => $payment->relationLoaded('revisions')
                ? $payment->revisions->map(fn (PaymentRevision $r) => [
                    'id' => $r->id,
                    'edited_by' => $r->editor ? ['id' => $r->editor->id, 'name' => $r->editor->name] : null,
                    'created_at' => $r->created_at,
                ])->values()->all()
                : [],
            'created_by' => $payment->relationLoaded('creator') && $payment->creator
                ? $this->catalog->presentUser($payment->creator)
                : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function line(PaymentLine $line): array
    {
        $isParts = $line->type === PaymentLineType::Parts;

        return [
            'id' => $line->id,
            'type' => $line->type->value,
            'type_label' => $line->type->label(),
            'store_id' => $line->store_id,
            'store' => $line->store ? ['id' => $line->store->id, 'store_number' => $line->store->store_number] : null,
            'other_store' => $line->other_store,
            'storage_location_id' => $line->storage_location_id,
            'storage_location' => $line->storageLocation ? ['id' => $line->storageLocation->id, 'name' => $line->storageLocation->name] : null,
            'description' => $line->description,
            'amount' => $this->money($line->amount),
            'hours' => $line->hours === null ? null : $this->money($line->hours),
            'rate' => $line->rate === null ? null : $this->money($line->rate),
            'hours_overridden' => (bool) $line->hours_overridden,
            'part_id' => $line->part_id,
            'part' => $line->part ? ['id' => $line->part->id, 'name' => $line->part->name, 'unit' => $line->part->unit ?? 'each'] : null,
            'quantity' => $line->quantity === null ? null : $this->money($line->quantity),
            'unit_cost' => $line->unit_cost === null ? null : number_format((float) $line->unit_cost, 4, '.', ''),
            'bought_from_supplier_id' => $line->bought_from_supplier_id,
            'bought_from' => $line->boughtFrom ? ['id' => $line->boughtFrom->id, 'name' => $line->boughtFrom->name] : null,
            'destination' => $isParts ? ($line->storage_location_id !== null ? 'storage' : 'ticket') : null,
            'miles' => $line->miles === null ? null : $this->money($line->miles),
            'issue_inspection_id' => $line->issue_inspection_id,
            'ticket_issues' => $line->ticketIssues->map(fn (TicketIssue $i) => $this->ticketIssue($i))->values()->all(),
            'trip_sessions' => $line->tripSessions->map(fn (TripSession $s) => [
                'id' => $s->id,
                'trip_id' => $s->trip_id,
                'clock_in' => $s->clock_in,
                'clock_out' => $s->clock_out,
                'paid_minutes' => (int) $s->pivot->paid_minutes,
            ])->values()->all(),
            'stock_movement_id' => $line->stockMovement?->id,
            'serials' => $line->partUnits->pluck('serial_number')->sort()->values()->all(),
            'technician_purchases' => $line->technicianPurchases->map(fn (TechnicianPurchase $p) => $this->purchase($p))->values()->all(),
        ];
    }

    /**
     * A closed, unpaid session with its durations and the issues of its trip.
     *
     * @return array<string, mixed>
     */
    public function session(TripSession $session): array
    {
        $durations = $this->trips->durations($session);
        $issues = $session->trip?->stops
            ->reject(fn ($stop) => (bool) $stop->mistaken)
            ->flatMap(fn ($stop) => $stop->ticketIssues)
            ->unique('id')
            ->map(fn (TicketIssue $i) => $this->ticketIssue($i))
            ->values()
            ->all() ?? [];

        return [
            'id' => $session->id,
            'trip_id' => $session->trip_id,
            'technician_id' => $session->technician_id,
            'clock_in' => $session->clock_in,
            'clock_out' => $session->clock_out,
            'using_car' => (bool) $session->using_car,
            'odometer_in' => $session->odometer_in,
            'odometer_out' => $session->odometer_out,
            'miles' => $session->odometer_in !== null && $session->odometer_out !== null
                ? max(0, $session->odometer_out - $session->odometer_in)
                : null,
            'source' => $session->source,
            'durations' => $durations,
            'paid_minutes' => $durations['paid'],
            'stores' => $session->trip?->stops
                ->reject(fn ($stop) => (bool) $stop->mistaken)
                ->map(fn ($stop) => $stop->store?->store_number ?? $stop->other_store)
                ->filter()->unique()->values()->all() ?? [],
            'ticket_issues' => $issues,
        ];
    }

    /**
     * A technician purchase (docs/MAINTENANCE-V2.md §4, "Presented").
     *
     * @return array<string, mixed>
     */
    public function purchase(TechnicianPurchase $purchase): array
    {
        return [
            'id' => $purchase->id,
            'technician' => $purchase->technician ? ['id' => $purchase->technician->id, 'name' => $purchase->technician->name] : null,
            'trip_id' => $purchase->trip_id,
            'ticket_issue' => $purchase->ticketIssue ? $this->ticketIssue($purchase->ticketIssue) : null,
            'part' => $purchase->part ? ['id' => $purchase->part->id, 'name' => $purchase->part->name] : null,
            'description' => $purchase->description,
            'quantity' => $this->money($purchase->quantity),
            'total_cost' => $this->money($purchase->total_cost),
            'supplier' => $purchase->supplier ? ['id' => $purchase->supplier->id, 'name' => $purchase->supplier->name] : null,
            'serial_number' => $purchase->serial_number,
            'bought_at' => $purchase->bought_at,
            'paid' => $purchase->payment_line_id !== null,
            'payment_id' => $purchase->paymentLine?->payment_id,
            'receipts' => $this->attachments->presentMany($purchase) ?? [],
            'mistaken' => (bool) $purchase->mistaken,
        ];
    }

    /**
     * @return array{id: int, ticket_id: int, title: string, store_number: string|null}
     */
    public function ticketIssue(TicketIssue $issue): array
    {
        return [
            'id' => $issue->id,
            'ticket_id' => $issue->ticket_id,
            'title' => $issue->displayTitle(),
            'store_number' => $issue->ticket?->store?->store_number ?? $issue->ticket?->other_store,
        ];
    }

    private function money(mixed $value): string
    {
        return number_format((float) $value, 2, '.', '');
    }
}
