<?php

namespace App\Services\MigrationV2;

use App\Support\NameKey;
use Illuminate\Support\Facades\DB;

/**
 * Shared plumbing for the v2 data converters. They work on the query builder,
 * never on models: models describe the v2 schema, and the rows being read are
 * the old one.
 */
abstract class Converter
{
    /** Where parts bought before v2 came from, when nobody wrote it down. */
    public const UNKNOWN_SUPPLIER = 'Unknown (migrated)';

    abstract public function run(): void;

    /**
     * Point the notes and files of an old row at the new row. With $copy the
     * old ones stay where they are and copies are made -- used when one old
     * row became several new ones.
     */
    protected function moveMorphs(string $fromClass, int $fromId, string $toClass, int $toId, bool $copy = false): void
    {
        foreach (['notes' => 'notable', 'attachments' => 'attachable'] as $table => $morph) {
            $query = DB::table($table)->where("{$morph}_type", $fromClass)->where("{$morph}_id", $fromId);

            if (! $copy) {
                $query->update(["{$morph}_type" => $toClass, "{$morph}_id" => $toId]);

                continue;
            }

            foreach ($query->get() as $row) {
                $data = (array) $row;
                unset($data['id']);
                $data["{$morph}_type"] = $toClass;
                $data["{$morph}_id"] = $toId;
                DB::table($table)->insert($data);
            }
        }
    }

    /**
     * The store of a ticket issue, as [store_id, other_store].
     *
     * @return array{0: int|null, 1: string|null}
     */
    protected function storeOfIssue(int $ticketIssueId): array
    {
        $ticket = DB::table('ticket_issues')
            ->join('tickets', 'tickets.id', '=', 'ticket_issues.ticket_id')
            ->where('ticket_issues.id', $ticketIssueId)
            ->first(['tickets.store_id', 'tickets.other_store']);

        return [$ticket?->store_id !== null ? (int) $ticket->store_id : null, $ticket?->other_store];
    }

    /**
     * An amount to 2 decimals, ROUNDED half away from zero (bcadd alone
     * truncates: 58.275 would become 58.27 where the old sheet said 58.28).
     * Accepts what the drivers hand back: strings, ints, floats (SQLite).
     */
    protected function money(mixed $value): string
    {
        return $this->round($this->decimal($value), 2);
    }

    protected function positive(mixed $value): bool
    {
        return $value !== null && bccomp($this->decimal($value), '0', 2) === 1;
    }

    protected function nonZero(mixed $value): bool
    {
        return $value !== null && bccomp($this->money($value), '0', 2) !== 0;
    }

    /** a × b, rounded to 2 decimals. */
    protected function times(mixed $a, mixed $b): string
    {
        return $this->round(bcmul($this->decimal($a), $this->decimal($b), 8), 2);
    }

    /** A well-formed decimal string bcmath accepts (no "1.0E-5", no ""). */
    protected function decimal(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '0';
        }
        if (is_float($value)) {
            return number_format($value, 8, '.', '');
        }

        $value = trim((string) $value);

        return is_numeric($value) && ! str_contains(strtolower($value), 'e')
            ? $value
            : number_format((float) $value, 8, '.', '');
    }

    private function round(string $value, int $scale): string
    {
        $half = '0.' . str_repeat('0', $scale) . '5';

        return bcadd($value, str_starts_with($value, '-') ? "-{$half}" : $half, $scale);
    }

    protected function unknownSupplierId(): int
    {
        $id = DB::table('suppliers')->where('name_key', NameKey::of(self::UNKNOWN_SUPPLIER))->value('id');

        if ($id !== null) {
            return (int) $id;
        }

        return (int) DB::table('suppliers')->insertGetId([
            'name' => self::UNKNOWN_SUPPLIER,
            'name_key' => NameKey::of(self::UNKNOWN_SUPPLIER),
            'description' => 'Parts bought before v2, where nobody wrote down the shop. Rename or merge once you know.',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
