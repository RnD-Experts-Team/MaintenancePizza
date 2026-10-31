<?php

namespace App\Services;

use App\Enums\PaymentLineType;
use App\Enums\StockMovementType;
use App\Models\IssueInspection;
use App\Models\Part;
use App\Models\PartUnit;
use App\Models\Payment;
use App\Models\PaymentLine;
use App\Models\PaymentRevision;
use App\Models\StockBalance;
use App\Models\StockMovement;
use App\Models\StoragePlaceValue;
use App\Models\Technician;
use App\Models\TechnicianPurchase;
use App\Models\TicketIssue;
use App\Models\TripSession;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Payments (docs/MAINTENANCE.md §4): one invoice we paid, to exactly one
 * technician or one supplier, split into lines by where the money went.
 *
 * The server works out every line's amount (the dashboard only previews it):
 *
 *   hourly      hours = paid minutes of the picked sessions / 60, unless
 *               hours are typed (hours_overridden); rate defaults to the
 *               technician's hourly rate; amount = hours × rate. A session
 *               is paid once: one already claimed is a 422 naming it.
 *   parts       amount = quantity × unit cost. Into storage: a Bought stock
 *               movement carrying this line. To a ticket: linked to the
 *               issue(s), no stock movement. Serial-tracked parts name each
 *               piece (part units, in stock or installed on the first issue).
 *               Technician purchases named on the line are paid back by it.
 *   gas         the amount typed, or miles × rate.
 *   fixed / inspection / other   the amount typed.
 *
 * The payment total is the sum of its lines.
 *
 * Editing replaces the lines in one transaction: a revision snapshot first,
 * then the old lines are UNDONE (their Bought movements reversed through
 * StockService, the units they created retired, session claims and purchases
 * released) and the new ones written. Marking mistaken is the same undo, but
 * the lines stay for the record.
 */
class PaymentService
{
    private const SCALE = 2;

    public function __construct(
        private TripService $trips,
        private StockService $stock,
        private NoteService $notes,
        private AttachmentService $attachments,
        private PaymentPresenter $presenter,
    ) {}

    // ------------------------------------------------------------------ Reads

    /**
     * @param  array<string, mixed>  $filters
     * @return array{page: LengthAwarePaginator, totals: array{count: int, total: string}}
     */
    public function list(array $filters, int $perPage): array
    {
        $query = $this->filtered($filters);

        $totals = [
            'count' => (clone $query)->count(),
            'total' => $this->money((clone $query)->sum('total')),
        ];

        $page = $query->with(PaymentPresenter::ROW_LOADS)
            ->orderByDesc('paid_at')
            ->orderByDesc('id')
            ->paginate($perPage)
            ->through(fn (Payment $p) => $this->presenter->row($p));

        return ['page' => $page, 'totals' => $totals];
    }

    /**
     * @return array<string, mixed>
     */
    public function show(Payment $payment): array
    {
        $payment->load(PaymentPresenter::SHOW_LOADS);

        return $this->presenter->show($payment, $this->placesOf($payment));
    }

    /**
     * Where the parts its storage lines bought sit now: the address of each
     * part's stock balance at the line's location, keyed
     * "part_id:storage_location_id". One query for all the lines. The place
     * belongs to the balance, not the line, so it is the current one -- it
     * moves when someone re-shelves the part.
     *
     * @return array<string, array<int, array<string, mixed>>>
     */
    private function placesOf(Payment $payment): array
    {
        $lines = $payment->lines->filter(fn (PaymentLine $l) => $l->type === PaymentLineType::Parts
            && $l->storage_location_id !== null
            && $l->part_id !== null);

        if ($lines->isEmpty()) {
            return [];
        }

        $wanted = $lines->map(fn (PaymentLine $l) => $l->part_id.':'.$l->storage_location_id)->unique()->all();

        return StockBalance::query()
            ->whereIn('part_id', $lines->pluck('part_id')->unique()->all())
            ->whereIn('storage_location_id', $lines->pluck('storage_location_id')->unique()->all())
            ->with(['places.placeLevel', 'places.placeValue'])
            ->get()
            ->filter(fn (StockBalance $b) => in_array($b->part_id.':'.$b->storage_location_id, $wanted, true))
            ->mapWithKeys(fn (StockBalance $b) => [$b->part_id.':'.$b->storage_location_id => $this->stock->presentPlace($b)])
            ->all();
    }

    /**
     * What has not been paid yet, per technician: closed sessions no payment
     * claims, and purchases nobody has paid back.
     *
     * @return array<int, array<string, mixed>>
     */
    public function unpaidWork(?int $technicianId): array
    {
        $sessions = TripSession::query()
            ->unpaid()
            ->whereHas('trip', fn (Builder $q) => $q->where('mistaken', false))
            ->when($technicianId !== null, fn ($q) => $q->where('technician_id', $technicianId))
            ->with(PaymentPresenter::SESSION_LOADS)
            ->orderBy('clock_in')
            ->get()
            ->groupBy('technician_id');

        $purchases = TechnicianPurchase::query()
            ->unpaid()
            ->when($technicianId !== null, fn ($q) => $q->where('technician_id', $technicianId))
            ->with(PaymentPresenter::PURCHASE_LOADS)
            ->orderBy('bought_at')
            ->get()
            ->groupBy('technician_id');

        $ids = $sessions->keys()->merge($purchases->keys())->unique()->all();

        return Technician::withTrashed()
            ->with('category')
            ->whereIn('id', $ids)
            ->orderBy('name')
            ->get()
            ->map(function (Technician $t) use ($sessions, $purchases) {
                $rows = ($sessions->get($t->id) ?? collect())->map(fn (TripSession $s) => $this->presenter->session($s))->values();
                $bought = ($purchases->get($t->id) ?? collect())->map(fn (TechnicianPurchase $p) => $this->presenter->purchase($p))->values();

                return [
                    'technician' => [
                        'id' => $t->id,
                        'name' => $t->name,
                        'phone' => $t->phone,
                        'hourly_rate' => $t->hourly_rate === null ? null : $this->money($t->hourly_rate),
                        'category' => $t->category ? ['id' => $t->category->id, 'name' => $t->category->name] : null,
                    ],
                    'sessions' => $rows->all(),
                    'purchases' => $bought->all(),
                    'totals' => [
                        'paid_minutes' => (int) $rows->sum('paid_minutes'),
                        'purchases_total' => $this->money($bought->reduce(fn (string $c, array $p) => bcadd($c, $p['total_cost'], self::SCALE), '0.00')),
                    ],
                ];
            })
            ->values()
            ->all();
    }

    // ----------------------------------------------------------------- Writes

    /**
     * @param  array<string, mixed>  $data
     * @param  array<int, UploadedFile>  $files
     */
    public function create(array $data, array $files): Payment
    {
        return DB::transaction(function () use ($data, $files) {
            $payment = new Payment($this->header($data));
            $payment->created_by = Auth::id();
            $payment->total = '0.00';
            $payment->save();

            $this->writeLines($payment, $data['lines']);
            $this->afterWrite($payment, $data, $files);

            return $payment;
        });
    }

    /**
     * Replace the payment and its lines. 409 when someone else saved it after
     * `expected_updated_at`.
     *
     * @param  array<string, mixed>  $data
     * @param  array<int, UploadedFile>  $files
     */
    public function edit(Payment $payment, array $data, array $files): Payment
    {
        return DB::transaction(function () use ($payment, $data, $files) {
            $payment = Payment::query()->lockForUpdate()->findOrFail($payment->id);

            if ($payment->mistaken) {
                throw ValidationException::withMessages([
                    'status' => 'This payment is marked mistaken and can no longer be edited. Record a new payment instead.',
                ]);
            }

            if (Carbon::parse($data['expected_updated_at'])->getTimestamp() !== $payment->updated_at?->getTimestamp()) {
                abort(409, 'Someone else changed this payment after you opened it. Reload it and make your change again.');
            }

            $this->snapshot($payment);
            $this->undoLines($payment, delete: true);

            $payment->fill($this->header($data));
            $payment->save();

            $this->writeLines($payment, $data['lines']);
            $this->afterWrite($payment, $data, $files);
            $payment->touch();

            return $payment;
        });
    }

    /**
     * The payment was a mistake: undo what its lines did (stock, session
     * claims, purchases paid back) but keep the lines for the record.
     */
    public function markMistaken(Payment $payment, string $reason): Payment
    {
        return DB::transaction(function () use ($payment, $reason) {
            $payment = Payment::query()->lockForUpdate()->findOrFail($payment->id);

            if ($payment->mistaken) {
                throw ValidationException::withMessages([
                    'status' => 'This payment is already marked mistaken.',
                ]);
            }

            // The snapshot keeps which sessions and purchases it paid, which
            // the release below takes off the lines.
            $this->snapshot($payment);
            $this->undoLines($payment, delete: false);

            $payment->mistaken = true;
            $payment->save();

            $this->notes->store($payment, $reason, 'mistaken', []);

            return $payment;
        });
    }

    // ---------------------------------------------------------------- Helpers

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function header(array $data): array
    {
        return [
            'paid_at' => $data['paid_at'],
            'technician_id' => $data['technician_id'] ?? null,
            'supplier_id' => $data['supplier_id'] ?? null,
            'method' => $data['method'] ?? null,
            'reference' => $data['reference'] ?? null,
            'invoice_number' => $data['invoice_number'] ?? null,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<int, UploadedFile>  $files
     */
    private function afterWrite(Payment $payment, array $data, array $files): void
    {
        $payment->total = $this->money(
            $payment->lines()->get()->reduce(fn (string $c, PaymentLine $l) => bcadd($c, $this->money($l->amount), self::SCALE), '0.00')
        );
        $payment->save();

        if (! empty($data['note']) && trim((string) $data['note']) !== '') {
            $this->notes->store($payment, trim((string) $data['note']), null, []);
        }

        $this->attachments->store($payment, $files);
    }

    private function snapshot(Payment $payment): void
    {
        PaymentRevision::query()->create([
            'payment_id' => $payment->id,
            'snapshot' => json_decode(json_encode($this->show($payment->fresh())), true),
            'edited_by' => Auth::id(),
        ]);
    }

    /**
     * Undo what the lines did: reverse their Bought movements (StockService
     * writes the `undone` movement and retires the units still on the shelf),
     * retire the units they installed straight on a ticket, release their
     * session claims and the purchases they paid back. With $delete the
     * lines go too (an edit replaces them).
     */
    private function undoLines(Payment $payment, bool $delete): void
    {
        foreach ($payment->lines()->get() as $line) {
            $movements = StockMovement::query()
                ->where('payment_line_id', $line->id)
                ->where('type', StockMovementType::Bought)
                ->where('mistaken', false)
                ->whereDoesntHave('reversal')
                ->get();

            foreach ($movements as $movement) {
                $this->stock->reverse($movement);
            }

            // Units this line put straight on a ticket, that no movement has
            // touched since.
            PartUnit::query()
                ->where('payment_line_id', $line->id)
                ->whereDoesntHave('movementLines')
                ->delete();

            $line->tripSessions()->detach();
            TechnicianPurchase::query()->where('payment_line_id', $line->id)->update(['payment_line_id' => null]);

            if ($delete) {
                $line->delete();
            }
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $lines
     */
    private function writeLines(Payment $payment, array $lines): void
    {
        $technician = $payment->technician_id !== null ? Technician::withTrashed()->find($payment->technician_id) : null;

        foreach (array_values($lines) as $i => $input) {
            $this->writeLine($payment, $technician, $i, $input);
        }
    }

    /**
     * @param  array<string, mixed>  $in
     */
    private function writeLine(Payment $payment, ?Technician $technician, int $i, array $in): void
    {
        $type = PaymentLineType::from((string) $in['type']);
        $key = fn (string $field) => "lines.{$i}.{$field}";
        $fail = fn (string $field, string $message) => throw ValidationException::withMessages([$key($field) => $message]);
        $has = fn (string $field) => array_key_exists($field, $in) && $in[$field] !== null && $in[$field] !== '' && $in[$field] !== [];

        // --- where the money went: exactly one place.
        $places = array_filter(['store_id', 'other_store', 'storage_location_id'], $has);
        if (count($places) !== 1) {
            $fail('store_id', 'Say where this line was spent: one store, an other store, or (parts for the shelf) a storage location.');
        }

        $destination = null;
        if ($type === PaymentLineType::Parts) {
            $destination = $in['destination'] ?? ($has('storage_location_id') ? 'storage' : 'ticket');
            if ($destination === 'storage' && ! $has('storage_location_id')) {
                $fail('storage_location_id', 'Parts going into storage need the storage location.');
            }
            if ($destination === 'ticket' && $has('storage_location_id')) {
                $fail('storage_location_id', 'Parts for a ticket are spent at the store, not a storage location.');
            }
        } elseif ($has('storage_location_id')) {
            $fail('storage_location_id', 'Only parts bought for storage go to a storage location.');
        }

        $line = new PaymentLine([
            'payment_id' => $payment->id,
            'type' => $type,
            'store_id' => $in['store_id'] ?? null,
            'other_store' => $has('other_store') ? trim((string) $in['other_store']) : null,
            'storage_location_id' => $in['storage_location_id'] ?? null,
            'description' => $in['description'] ?? null,
            'sort_order' => $i,
            'amount' => '0.00',
        ]);

        $sessions = collect();
        $sessionMinutes = [];
        $part = null;
        $serials = array_values(array_filter(array_map(fn ($s) => trim((string) $s), (array) ($in['serials'] ?? [])), fn ($s) => $s !== ''));
        $issueIds = array_values(array_unique(array_map('intval', (array) ($in['ticket_issue_ids'] ?? []))));

        if (! empty($in['issue_inspection_id'])) {
            if ($type !== PaymentLineType::Inspection) {
                $fail('issue_inspection_id', 'Only an inspection line can name the inspection it pays.');
            }
            $line->issue_inspection_id = (int) $in['issue_inspection_id'];
            // The inspection's issue is what this line paid for.
            $issueIds[] = (int) IssueInspection::query()->whereKey($line->issue_inspection_id)->value('ticket_issue_id');
            $issueIds = array_values(array_unique(array_filter($issueIds)));
        }

        if ($type !== PaymentLineType::Parts && $serials !== []) {
            $fail('serials', 'Only a parts line has serial numbers.');
        }
        if ($type !== PaymentLineType::Parts && ! empty($in['technician_purchase_ids'])) {
            $fail('technician_purchase_ids', 'Technician purchases are paid back on a parts line.');
        }

        switch ($type) {
            case PaymentLineType::Hourly:
                if ($technician === null) {
                    $fail('type', 'Only a technician is paid by the hour.');
                }

                $ids = array_values(array_unique(array_map('intval', (array) ($in['trip_session_ids'] ?? []))));
                $sessions = TripSession::query()->with('events')->whereIn('id', $ids)->get()->keyBy('id');

                foreach ($ids as $id) {
                    $session = $sessions->get($id);
                    $label = $session ? "The session of {$session->clock_in?->format('M j, g:i A')} (#{$id})" : "Session #{$id}";

                    if ($session === null || (int) $session->technician_id !== (int) $technician->id) {
                        $fail('trip_session_ids', "{$label} is not one of {$technician->name}'s sessions.");
                    }
                    if ($session->mistaken) {
                        $fail('trip_session_ids', "{$label} was marked mistaken and cannot be paid.");
                    }
                    if ($session->clock_out === null) {
                        $fail('trip_session_ids', "{$label} is still open: clock it out before paying it.");
                    }

                    $claim = DB::table('payment_line_trip_session')
                        ->join('payment_lines', 'payment_lines.id', '=', 'payment_line_trip_session.payment_line_id')
                        ->where('payment_line_trip_session.trip_session_id', $id)
                        ->value('payment_lines.payment_id');
                    if ($claim !== null) {
                        $fail('trip_session_ids', "{$label} is already paid on payment #{$claim}.");
                    }

                    $sessionMinutes[$id] = $this->trips->paidMinutes($session);
                }

                if ($has('hours')) {
                    $hours = $this->money($in['hours']);
                    $line->hours_overridden = true;
                } elseif ($ids !== []) {
                    $hours = $this->money(array_sum($sessionMinutes) / 60);
                    $line->hours_overridden = false;
                } else {
                    $fail('trip_session_ids', 'Pick the sessions this pays for, or type the hours.');
                }

                $rate = $has('rate') ? (string) $in['rate'] : ($technician->hourly_rate === null ? null : (string) $technician->hourly_rate);
                if ($rate === null) {
                    $fail('rate', "{$technician->name} has no hourly rate: type the rate for this line.");
                }

                $line->hours = $hours;
                $line->rate = number_format((float) $rate, 4, '.', '');
                $line->amount = $this->multiply($hours, $rate);
                break;

            case PaymentLineType::Parts:
                foreach (['part_id' => 'Pick the part.', 'quantity' => 'Give the quantity.', 'unit_cost' => 'Give the price of one.'] as $field => $message) {
                    if (! $has($field)) {
                        $fail($field, $message);
                    }
                }

                $part = Part::withTrashed()->findOrFail((int) $in['part_id']);
                $quantity = $this->money($in['quantity']);

                if ($part->track_serials) {
                    if (bccomp($quantity, $this->money(count($serials)), self::SCALE) !== 0 || count(array_unique($serials)) !== count($serials)) {
                        $fail('serials', "{$part->name} is tracked by serial number: give one different serial for each piece ({$quantity} needed, ".count($serials).' given).');
                    }
                } elseif ($serials !== []) {
                    $fail('serials', "{$part->name} is not tracked by serial number.");
                }

                if ($destination === 'ticket' && $issueIds === []) {
                    $fail('ticket_issue_ids', 'Parts for a ticket need the ticket issue(s) they were for.');
                }
                if ($destination === 'storage' && $issueIds !== []) {
                    $fail('ticket_issue_ids', 'Parts bought for storage are not linked to a ticket: record them as Used on the ticket when they are taken.');
                }

                $line->part_id = $part->id;
                $line->quantity = $quantity;
                $line->unit_cost = number_format((float) $in['unit_cost'], 4, '.', '');
                $line->bought_from_supplier_id = $in['bought_from_supplier_id'] ?? null;
                $line->amount = $this->multiply($quantity, (string) $in['unit_cost']);
                break;

            case PaymentLineType::Gas:
                if ($has('miles')) {
                    $line->miles = $this->money($in['miles']);
                }
                if ($has('amount')) {
                    $line->amount = $this->money($in['amount']);
                } elseif ($has('miles') && $has('rate')) {
                    $line->rate = number_format((float) $in['rate'], 4, '.', '');
                    $line->amount = $this->multiply((string) $in['miles'], (string) $in['rate']);
                } else {
                    $fail('amount', 'Give the amount, or the miles and the rate per mile.');
                }
                break;

            default: // fixed, inspection, other
                if (! $has('amount')) {
                    $fail('amount', 'Give the amount.');
                }
                $line->amount = $this->money($in['amount']);
        }

        // --- the issues it paid for must be at the line's store.
        $issues = TicketIssue::query()->with(['ticket.store', 'issue'])->whereIn('id', $issueIds)->get()->keyBy('id');
        foreach ($issueIds as $id) {
            $issue = $issues->get($id);
            if ($issue === null) {
                $fail('ticket_issue_ids', "Ticket issue #{$id} does not exist.");
            }
            if ($line->store_id !== null && (int) $issue->ticket?->store_id !== (int) $line->store_id) {
                $fail('ticket_issue_ids', "\"{$issue->displayTitle()}\" (ticket #{$issue->ticket_id}) is not at this line's store.");
            }
            if ($line->other_store !== null && $issue->ticket?->store_id !== null) {
                $fail('ticket_issue_ids', "\"{$issue->displayTitle()}\" (ticket #{$issue->ticket_id}) is at store {$issue->ticket->store?->store_number}, not an other store.");
            }
        }

        $line->save();

        $line->ticketIssues()->sync($issueIds);
        foreach ($sessionMinutes as $id => $minutes) {
            try {
                $line->tripSessions()->attach($id, ['paid_minutes' => $minutes]);
            } catch (UniqueConstraintViolationException) {
                // Another payment claimed it between the check and now; the
                // unique index is the real guard.
                $fail('trip_session_ids', "Session #{$id} was just paid on another payment. Reload and try again.");
            }
        }

        if ($type === PaymentLineType::Parts) {
            $this->payBackPurchases($line, $payment, $i, (array) ($in['technician_purchase_ids'] ?? []));

            if ($destination === 'storage') {
                $this->stock->record([
                    'moved_at' => $payment->paid_at,
                    'type' => StockMovementType::Bought,
                    'storage_location_id' => $line->storage_location_id,
                    'payment_line_id' => $line->id,
                    'body' => "Bought on payment #{$payment->id}.",
                ], [[
                    'part_id' => $part->id,
                    'storage_location_id' => $line->storage_location_id,
                    'quantity' => $line->quantity,
                    'direction' => 1,
                    'unit_cost' => $line->unit_cost,
                    'serials' => $serials,
                    'serials_key' => $key('serials'),
                ]]);

                $this->setPlace($line, $i, (array) ($in['place'] ?? []));
            } elseif ($serials !== []) {
                $this->installUnits($line, $part, $issueIds[0], $serials, $payment, $key('serials'));
            }
        }
    }

    /**
     * Mark the technician's purchases as paid back by this line.
     *
     * @param  array<int, mixed>  $ids
     */
    private function payBackPurchases(PaymentLine $line, Payment $payment, int $i, array $ids): void
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        if ($ids === []) {
            return;
        }

        $key = "lines.{$i}.technician_purchase_ids";
        $purchases = TechnicianPurchase::query()->whereIn('id', $ids)->lockForUpdate()->get()->keyBy('id');

        foreach ($ids as $id) {
            $purchase = $purchases->get($id);

            if ($purchase === null || $payment->technician_id === null || (int) $purchase->technician_id !== (int) $payment->technician_id) {
                throw ValidationException::withMessages([$key => "Purchase #{$id} is not one this technician made: only his own purchases are paid back to him."]);
            }
            if ($purchase->mistaken) {
                throw ValidationException::withMessages([$key => "Purchase #{$id} ({$purchase->description}) was marked mistaken."]);
            }
            if ($purchase->payment_line_id !== null) {
                $paidOn = PaymentLine::query()->whereKey($purchase->payment_line_id)->value('payment_id');

                throw ValidationException::withMessages([$key => "Purchase #{$id} ({$purchase->description}) is already paid back on payment #{$paidOn}."]);
            }

            $purchase->payment_line_id = $line->id;
            $purchase->save();
        }
    }

    /**
     * Pieces bought straight for a ticket: installed on its first issue.
     *
     * @param  array<int, string>  $serials
     */
    private function installUnits(PaymentLine $line, Part $part, int $issueId, array $serials, Payment $payment, string $key): void
    {
        $taken = PartUnit::query()->where('part_id', $part->id)->whereIn('serial_number', $serials)->pluck('serial_number')->all();
        if ($taken !== []) {
            throw ValidationException::withMessages([$key => "Serial {$taken[0]} of {$part->name} is already recorded."]);
        }

        foreach ($serials as $serial) {
            PartUnit::query()->create([
                'part_id' => $part->id,
                'serial_number' => $serial,
                'status' => 'installed',
                'ticket_issue_id' => $issueId,
                'payment_line_id' => $line->id,
                'installed_at' => $payment->paid_at,
                'created_by' => Auth::id(),
            ]);
        }
    }

    /**
     * Where the bought parts sit inside the storage location: the complete
     * address, as on the stock page.
     *
     * @param  array<int, array<string, mixed>>  $place  [{level_id, value_id}]
     */
    private function setPlace(PaymentLine $line, int $i, array $place): void
    {
        if ($place === []) {
            return;
        }

        $valueIds = [];
        foreach ($place as $j => $entry) {
            $value = StoragePlaceValue::query()->with('placeLevel')->find((int) ($entry['value_id'] ?? 0));

            if ($value === null
                || (int) $value->storage_place_level_id !== (int) ($entry['level_id'] ?? 0)
                || (int) $value->placeLevel?->storage_location_id !== (int) $line->storage_location_id) {
                throw ValidationException::withMessages([
                    "lines.{$i}.place.{$j}" => 'That place is not in this storage location.',
                ]);
            }

            $valueIds[] = $value->id;
        }

        $balance = StockBalance::query()
            ->where('part_id', $line->part_id)
            ->where('storage_location_id', $line->storage_location_id)
            ->firstOrFail();

        try {
            $this->stock->setBalancePlace($balance, $valueIds);
        } catch (ValidationException $e) {
            throw ValidationException::withMessages(["lines.{$i}.place" => collect($e->errors())->flatten()->first()]);
        }
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return Builder<Payment>
     */
    private function filtered(array $filters): Builder
    {
        $query = Payment::query();

        if (empty($filters['include_mistaken']) || ! filter_var($filters['include_mistaken'], FILTER_VALIDATE_BOOLEAN)) {
            $query->where('mistaken', false);
        }
        if (! empty($filters['from'])) {
            $query->whereDate('paid_at', '>=', $filters['from']);
        }
        if (! empty($filters['to'])) {
            $query->whereDate('paid_at', '<=', $filters['to']);
        }
        if (! empty($filters['technician_id'])) {
            $query->where('technician_id', (int) $filters['technician_id']);
        }
        if (! empty($filters['supplier_id'])) {
            $query->where('supplier_id', (int) $filters['supplier_id']);
        }
        if (! empty($filters['category_id'])) {
            $query->whereHas('technician', fn (Builder $q) => $q->where('category_id', (int) $filters['category_id']));
        }
        if (! empty($filters['store'])) {
            $stores = array_filter((array) $filters['store']);
            $query->whereHas('lines', fn (Builder $q) => $q->where(fn (Builder $w) => $w
                ->whereHas('store', fn (Builder $s) => $s->whereIn('store_number', $stores))
                ->orWhereIn('other_store', $stores)));
        }
        if (! empty($filters['type'])) {
            $types = array_filter(is_array($filters['type']) ? $filters['type'] : explode(',', (string) $filters['type']));
            $query->whereHas('lines', fn (Builder $q) => $q->whereIn('type', $types));
        }

        $search = trim((string) ($filters['search'] ?? ''));
        if ($search !== '') {
            $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $search).'%';
            $query->where(fn (Builder $q) => $q
                ->where('invoice_number', 'like', $like)
                ->orWhere('reference', 'like', $like)
                ->orWhereHas('technician', fn (Builder $t) => $t->where('name', 'like', $like))
                ->orWhereHas('supplier', fn (Builder $s) => $s->where('name', 'like', $like)));
        }

        return $query;
    }

    /** a × b, rounded half up to cents. */
    private function multiply(string $a, string $b): string
    {
        $product = bcmul(number_format((float) $a, 4, '.', ''), number_format((float) $b, 4, '.', ''), 6);

        return $this->money(round((float) $product, 2));
    }

    private function money(mixed $value): string
    {
        return number_format(round((float) $value, 2), 2, '.', '');
    }
}
