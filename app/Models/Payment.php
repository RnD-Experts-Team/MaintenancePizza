<?php

namespace App\Models;

use App\Enums\PaymentKind;
use App\Enums\PaymentMethod as LegacyPaymentMethod;
use App\Models\Concerns\HasNotesAndAttachments;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;

/**
 * The record of one invoice we paid: when, to whom (exactly one technician
 * XOR one supplier), how, the invoice number and file (an attachment). Its
 * lines split the money by store. `total` is the sum of the lines, kept on
 * the row so lists and sums never have to add them up again.
 *
 * Never deleted: a wrong payment is marked mistaken, which undoes what its
 * lines did (stock, session claims, purchases paid back) but keeps the lines
 * for the record.
 *
 * A refund is a row of its own (`kind = refund`): money that came back for
 * the payment `refund_of_payment_id`, dated when it came back, with negative
 * lines that each point at the line they refund. Undoing a refund is marking
 * it mistaken, like any payment.
 */
class Payment extends Model
{
    use HasNotesAndAttachments;

    /** A new row is a payment unless said otherwise (the column's own default). */
    protected $attributes = ['kind' => 'payment'];

    protected $fillable = [
        'kind',
        'refund_of_payment_id',
        'paid_at',
        'technician_id',
        'supplier_id',
        'payment_method_id',
        'method',
        'reference',
        'invoice_number',
        'total',
        'mistaken',
        'created_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'kind' => PaymentKind::class,
            'paid_at' => 'date',
            // Old fixed list, kept for older readers; new payments use payment_method_id.
            'method' => LegacyPaymentMethod::class,
            'total' => 'decimal:2',
            'mistaken' => 'boolean',
        ];
    }

    /** @return BelongsTo<Technician, $this> */
    public function technician(): BelongsTo
    {
        return $this->belongsTo(Technician::class)->withTrashed();
    }

    /** How it was paid, from the editable list. */
    /** @return BelongsTo<PaymentMethod, $this> */
    public function paymentMethod(): BelongsTo
    {
        return $this->belongsTo(PaymentMethod::class);
    }

    /** @return BelongsTo<Supplier, $this> */
    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class)->withTrashed();
    }

    /** @return HasMany<PaymentLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(PaymentLine::class)->orderBy('sort_order')->orderBy('id');
    }

    /** The payment a refund gives money back for. */
    /** @return BelongsTo<Payment, $this> */
    public function refundOf(): BelongsTo
    {
        return $this->belongsTo(Payment::class, 'refund_of_payment_id');
    }

    /** The refunds of this payment, mistaken ones included. */
    /** @return HasMany<Payment, $this> */
    public function refunds(): HasMany
    {
        return $this->hasMany(Payment::class, 'refund_of_payment_id')->orderBy('paid_at')->orderBy('id');
    }

    public function isRefund(): bool
    {
        return $this->kind === PaymentKind::Refund;
    }

    /** @return HasMany<PaymentRevision, $this> */
    public function revisions(): HasMany
    {
        return $this->hasMany(PaymentRevision::class)->latest('id');
    }

    /**
     * Why it was marked mistaken: the `mistaken` note markMistaken() writes
     * (the latest one, should there ever be more). Its own relation so a
     * list row can read the reason without loading every note.
     *
     * @return MorphOne<Note, $this>
     */
    public function mistakenNote(): MorphOne
    {
        return $this->morphOne(Note::class, 'notable')
            ->ofMany(['id' => 'max'], fn ($q) => $q->where('type', 'mistaken'));
    }

    /**
     * Why the money came back: the `refund` note refund() writes on a refund.
     *
     * @return MorphOne<Note, $this>
     */
    public function refundNote(): MorphOne
    {
        return $this->morphOne(Note::class, 'notable')
            ->ofMany(['id' => 'max'], fn ($q) => $q->where('type', 'refund'));
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Who was paid, as {type, id, name}. Null only on a malformed row.
     *
     * @return array{type: string, id: int, name: string}|null
     */
    public function paidTo(): ?array
    {
        if ($this->technician_id !== null) {
            return ['type' => 'technician', 'id' => (int) $this->technician_id, 'name' => (string) $this->technician?->name];
        }

        if ($this->supplier_id !== null) {
            return ['type' => 'supplier', 'id' => (int) $this->supplier_id, 'name' => (string) $this->supplier?->name];
        }

        return null;
    }
}
