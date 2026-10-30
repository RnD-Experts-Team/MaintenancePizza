<?php

namespace App\Services\MigrationV2;

use App\Support\NameKey;
use Illuminate\Support\Facades\DB;

/**
 * Fills `name_key` for every row of a table and renames the rows whose names
 * collide, ignoring capitals and extra spaces (migration 2026_11_01_000010).
 *
 * The OLDEST row of each name keeps it; later ones become "Name (2)",
 * "Name (3)" in id order. A suffix never takes a name somebody already has:
 * every name that is not itself a duplicate is reserved first, so a real
 * "Pump (2)" keeps its name even when a second "Pump" comes before it.
 * Soft-deleted rows count (restoring one must not create a duplicate).
 * Every rename is written to the conversion report.
 */
final class UniqueNames
{
    public function fill(string $table): void
    {
        $rows = DB::table($table)->orderBy('id')->get(['id', 'name']);

        // Pass 1: the first row of each key keeps its (tidied) name.
        $taken = [];
        $keeps = [];
        foreach ($rows as $row) {
            $key = NameKey::of($this->base($row));
            if (! isset($taken[$key])) {
                $taken[$key] = true;
                $keeps[(int) $row->id] = true;
            }
        }

        // Pass 2: write the keys; the duplicates get the first free suffix.
        foreach ($rows as $row) {
            $name = $this->base($row);
            $candidate = $name;
            $n = 1;

            if (! isset($keeps[(int) $row->id])) {
                do {
                    $n++;
                    $candidate = "{$name} ({$n})";
                } while (isset($taken[NameKey::of($candidate)]));

                $taken[NameKey::of($candidate)] = true;
            }

            if ($candidate !== $row->name) {
                LegacyMap::report('unique_names', "Renamed {$table} #{$row->id} from \"{$row->name}\" to \"{$candidate}\"", [
                    'table' => $table,
                    'id' => $row->id,
                    'from' => $row->name,
                    'to' => $candidate,
                ], $n > 1 ? 'warning' : 'info');
            }

            DB::table($table)->where('id', $row->id)->update([
                'name' => $candidate,
                'name_key' => NameKey::of($candidate),
            ]);
        }
    }

    private function base(object $row): string
    {
        $tidy = NameKey::tidy($row->name);

        return $tidy !== '' ? $tidy : "Unnamed #{$row->id}";
    }
}
