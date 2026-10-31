<?php

namespace App\Services;

use App\Models\MaintenanceSetting;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * The numbers the owner may change without a release (docs/MAINTENANCE.md
 * §2 "Settings"): the approval thresholds and how long an idle trip link
 * lives. An empty maintenance_settings table is a valid, fully working
 * configuration -- every read falls back to the contract's defaults.
 *
 * approval_levels is an ordered list of {max, level, label}: `max` is the
 * EXCLUSIVE upper bound of the amounts that level may approve, and the last
 * level has max null (no limit). So with the defaults 299.99 is the
 * specialist's, 300.00 the manager's and 1000.00 management's.
 */
class MaintenanceSettingsService
{
    public const APPROVAL_LEVELS = 'approval_levels';

    public const TRIP_LINK_IDLE_HOURS = 'trip_link_idle_hours';

    /** @var list<array{max: int|float|null, level: string, label: string}> */
    public const DEFAULT_APPROVAL_LEVELS = [
        ['max' => 300, 'level' => 'specialist', 'label' => 'Maintenance Specialist'],
        ['max' => 1000, 'level' => 'manager', 'label' => 'Maintenance Manager'],
        ['max' => null, 'level' => 'management', 'label' => 'Management'],
    ];

    public const MIN_LINK_IDLE_HOURS = 1;

    public const MAX_LINK_IDLE_HOURS = 720;

    /**
     * The levels, read once per request: a ticket's issue list asks for a
     * label per approval, and that must not be a query each. Keyed on the
     * request object, because a controller (and so this service) can outlive
     * one request -- the router keeps controller instances -- and a change
     * saved in between must be seen by the next request.
     *
     * @var list<array{max: int|float|null, level: string, label: string}>|null
     */
    private ?array $levels = null;

    private ?object $levelsFor = null;

    /**
     * @return array{approval_levels: list<array{max: string|null, level: string, label: string}>, trip_link_idle_hours: int}
     */
    public function all(): array
    {
        return [
            self::APPROVAL_LEVELS => array_map(fn (array $l) => [
                'max' => $l['max'] === null ? null : number_format((float) $l['max'], 2, '.', ''),
                'level' => $l['level'],
                'label' => $l['label'],
            ], $this->approvalLevels()),
            self::TRIP_LINK_IDLE_HOURS => $this->tripLinkIdleHours(),
        ];
    }

    /**
     * @param  array{approval_levels?: array<int, array<string, mixed>>, trip_link_idle_hours?: int}  $data
     * @return array<string, mixed>
     */
    public function update(array $data, ?User $by = null): array
    {
        DB::transaction(function () use ($data, $by) {
            if (array_key_exists(self::APPROVAL_LEVELS, $data)) {
                $levels = array_map(fn (array $l) => [
                    'max' => isset($l['max']) && $l['max'] !== '' ? (float) $l['max'] : null,
                    'level' => trim((string) $l['level']),
                    'label' => trim((string) $l['label']),
                ], array_values($data[self::APPROVAL_LEVELS]));

                MaintenanceSetting::put(self::APPROVAL_LEVELS, $levels, $by?->id);
                $this->levels = null;
            }

            if (array_key_exists(self::TRIP_LINK_IDLE_HOURS, $data)) {
                MaintenanceSetting::put(self::TRIP_LINK_IDLE_HOURS, (int) $data[self::TRIP_LINK_IDLE_HOURS], $by?->id);
            }
        });

        return $this->all();
    }

    /**
     * The levels in force, lowest first. A stored value that is not a usable
     * list (hand-edited, half-migrated) falls back to the defaults rather
     * than leaving amounts with no level.
     *
     * @return list<array{max: int|float|null, level: string, label: string}>
     */
    public function approvalLevels(): array
    {
        $request = app()->bound('request') ? app('request') : null;

        if ($this->levels === null || $this->levelsFor !== $request) {
            $this->levels = $this->readLevels();
            $this->levelsFor = $request;
        }

        return $this->levels;
    }

    /**
     * @return list<array{max: int|float|null, level: string, label: string}>
     */
    private function readLevels(): array
    {
        $stored = MaintenanceSetting::get(self::APPROVAL_LEVELS);

        if (!is_array($stored) || $stored === [] || self::shapeErrors($stored) !== []) {
            return self::DEFAULT_APPROVAL_LEVELS;
        }

        return array_values(array_map(fn (array $l) => [
            'max' => $l['max'] === null ? null : (float) $l['max'],
            'level' => (string) $l['level'],
            'label' => (string) $l['label'],
        ], $stored));
    }

    public function tripLinkIdleHours(): int
    {
        $hours = (int) MaintenanceSetting::get(self::TRIP_LINK_IDLE_HOURS, TripService::DEFAULT_LINK_IDLE_HOURS);

        return $hours >= self::MIN_LINK_IDLE_HOURS && $hours <= self::MAX_LINK_IDLE_HOURS
            ? $hours
            : TripService::DEFAULT_LINK_IDLE_HOURS;
    }

    /**
     * Who may approve this amount: the first level whose (exclusive) max is
     * above it, or the unlimited last level.
     *
     * @return array{amount: string, level: string, label: string}
     */
    public function levelFor(string|int|float $amount): array
    {
        $value = (float) $amount;
        $levels = $this->approvalLevels();
        $chosen = end($levels);

        foreach ($levels as $level) {
            if ($level['max'] === null || $value < (float) $level['max']) {
                $chosen = $level;
                break;
            }
        }

        return [
            'amount' => number_format($value, 2, '.', ''),
            'level' => $chosen['level'],
            'label' => $chosen['label'],
        ];
    }

    /**
     * The label of a stored level key (an approval keeps its level, the
     * label can be renamed since). Unknown keys read as themselves.
     */
    public function labelOf(string $level): string
    {
        foreach ($this->approvalLevels() as $l) {
            if ($l['level'] === $level) {
                return $l['label'];
            }
        }

        foreach (self::DEFAULT_APPROVAL_LEVELS as $l) {
            if ($l['level'] === $level) {
                return $l['label'];
            }
        }

        return ucfirst(str_replace('_', ' ', $level));
    }

    /**
     * What is wrong with an approval_levels list, in plain words, keyed by
     * the field at fault. Empty when it is usable: at least one level, each
     * with a level key and a label, keys distinct, maxes rising, and only
     * the last one without a max.
     *
     * @param  array<int|string, mixed>  $levels
     * @return array<string, string>
     */
    public static function shapeErrors(array $levels): array
    {
        $errors = [];
        $levels = array_values($levels);

        if ($levels === []) {
            return [self::APPROVAL_LEVELS => 'Give at least one approval level.'];
        }

        $seen = [];
        $previous = null;
        $last = count($levels) - 1;

        foreach ($levels as $i => $level) {
            $field = self::APPROVAL_LEVELS.".{$i}";

            if (!is_array($level)) {
                $errors[$field] = 'Each approval level needs a level, a label and a max.';
                continue;
            }

            $key = trim((string) ($level['level'] ?? ''));
            $label = trim((string) ($level['label'] ?? ''));
            $max = $level['max'] ?? null;
            $max = $max === '' ? null : $max;

            if ($key === '') {
                $errors["{$field}.level"] = 'Each approval level needs a short name (level).';
            } elseif (isset($seen[strtolower($key)])) {
                $errors["{$field}.level"] = "The level \"{$key}\" is listed twice.";
            }
            $seen[strtolower($key)] = true;

            if ($label === '') {
                $errors["{$field}.label"] = 'Each approval level needs a label.';
            }

            if ($i === $last) {
                if ($max !== null) {
                    $errors["{$field}.max"] = 'The last approval level must have no limit (max empty), so every amount has someone who can approve it.';
                }
                continue;
            }

            if ($max === null || !is_numeric($max) || (float) $max <= 0) {
                $errors["{$field}.max"] = 'Only the last approval level can be without a limit; give this one an amount above 0.';
                continue;
            }

            if ($previous !== null && (float) $max <= $previous) {
                $errors["{$field}.max"] = 'Each approval level must allow more than the one before it.';
            }
            $previous = (float) $max;
        }

        return $errors;
    }
}
