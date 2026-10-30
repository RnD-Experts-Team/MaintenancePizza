<?php

namespace App\Models;

use App\Enums\PaymentMethod;
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
 */
class Payment extends Model
{
    use HasNotesAndAttachments;

    protected $fillable = [
        'paid_at',
        'technician_id',
        'supplier_id',
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
            'paid_at' => 'date',
            'method' => PaymentMethod::class,
            'total' => 'decimal:2',
            'mistaken' => 'boolean',
        ];
    }

    /** @return BelongsTo<Technician, $this> */
    public function technician(): BelongsTo
    {
        return $this->belongsTo(Technician::class)->withTrashed();
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
