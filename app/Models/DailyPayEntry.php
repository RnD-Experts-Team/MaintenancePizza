<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One day's pay sheet. It holds payments (one per payee), and each payment
 * holds lines (one per store).
 */
class DailyPayEntry extends Model
{
    use HasFactory;

    protected $fillable = ['date'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'date' => 'date',
        ];
    }

    /** @return HasMany<DailyPayPayment, $this> */
    public function payments(): HasMany
    {
        return $this->hasMany(DailyPayPayment::class);
    }

    /**
     * Every line across every payment. Kept as a direct relation (the lines
     * still carry daily_pay_entry_id) so the store/technician filters in
     * DailyPayEntryService::list() stay one join deep.
     *
     * @return HasMany<DailyPayLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(DailyPayLine::class);
    }

    /** @return HasMany<DailyPayEntryRevision, $this> */
    public function revisions(): HasMany
    {
        return $this->hasMany(DailyPayEntryRevision::class)->latest();
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
