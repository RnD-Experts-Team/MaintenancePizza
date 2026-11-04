<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The few numbers the owner may change without a release (approval
 * thresholds, how long an idle trip link lives), as key → JSON value.
 *
 * Read through get() so a missing row falls back to the caller's default:
 * an empty table is a valid, fully-working configuration.
 */
class MaintenanceSetting extends Model
{
    protected $fillable = ['key', 'value', 'updated_by'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'value' => 'json',
        ];
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        $row = static::query()->where('key', $key)->first();

        return $row === null || $row->value === null ? $default : $row->value;
    }

    public static function put(string $key, mixed $value, ?int $updatedBy = null): self
    {
        return static::query()->updateOrCreate(
            ['key' => $key],
            ['value' => $value, 'updated_by' => $updatedBy ?? auth()->id()],
        );
    }

    /** @return BelongsTo<User, $this> */
    public function editor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
