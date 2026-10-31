<?php

namespace App\Services\LegacyConversion;

use Illuminate\Support\Facades\DB;

/**
 * Old row → new row, and what the conversion had to say about it.
 *
 * Every converter asks `has()` before it writes, so running a migration twice
 * (or resuming one that failed half way) never converts the same row twice.
 */
final class LegacyMap
{
    /**
     * The "new table" of an old row that was looked at and deliberately not
     * converted (it is in the report): mapping it makes the next run skip it
     * instead of reporting it again.
     */
    public const SKIPPED = 'skipped';

    public static function has(string $legacyTable, int $legacyId, string $newTable): bool
    {
        return DB::table('legacy_conversion_map')
            ->where('legacy_table', $legacyTable)
            ->where('legacy_id', $legacyId)
            ->where('new_table', $newTable)
            ->exists();
    }

    /** The first new row an old row became, or null. */
    public static function get(string $legacyTable, int $legacyId, string $newTable): ?int
    {
        $id = DB::table('legacy_conversion_map')
            ->where('legacy_table', $legacyTable)
            ->where('legacy_id', $legacyId)
            ->where('new_table', $newTable)
            ->orderBy('id')
            ->value('new_id');

        return $id === null ? null : (int) $id;
    }

    /**
     * Every new row an old row became.
     *
     * The map's unique key includes new_id, so every new row is recorded.
     * The converters that make several rows of one old row (sessions of an
     * entry, copies of a booking, inspections of a diagnosis) were written
     * when it did not, and still find their rows from the data; both agree.
     *
     * @return list<int>
     */
    public static function all(string $legacyTable, int $legacyId, string $newTable): array
    {
        return DB::table('legacy_conversion_map')
            ->where('legacy_table', $legacyTable)
            ->where('legacy_id', $legacyId)
            ->where('new_table', $newTable)
            ->orderBy('id')
            ->pluck('new_id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    public static function put(string $legacyTable, int $legacyId, string $newTable, int $newId): void
    {
        DB::table('legacy_conversion_map')->insertOrIgnore([
            'legacy_table' => $legacyTable,
            'legacy_id' => $legacyId,
            'new_table' => $newTable,
            'new_id' => $newId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** @param  array<string, mixed>  $context */
    public static function report(string $step, string $message, array $context = [], string $level = 'info'): void
    {
        DB::table('legacy_conversion_report')->insert([
            'step' => $step,
            'level' => $level,
            'message' => $message,
            'context' => $context === [] ? null : json_encode($context),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
