<?php

namespace App\Enums;

/**
 * How an issue ended, set when it is closed. `deferred` is only ever the
 * converted form of the old "deferred" status; closing in v2 offers the
 * other four (closable()).
 */
enum Resolution: string
{
    case Repaired = 'repaired';
    case FixedByTroubleshooting = 'fixed_by_troubleshooting';
    case NotNeeded = 'not_needed';
    case Replaced = 'replaced';
    case Deferred = 'deferred';

    public function label(): string
    {
        return match ($this) {
            self::Repaired => 'Repaired',
            self::FixedByTroubleshooting => 'Fixed by troubleshooting',
            self::NotNeeded => 'Not needed',
            self::Replaced => 'Replaced',
            self::Deferred => 'Deferred',
        };
    }

    /**
     * Endings that involved no paid work, so the close checklist does not ask
     * for a cost, an invoice or work notes.
     */
    public function exemptsChecklist(): bool
    {
        return in_array($this, [self::FixedByTroubleshooting, self::NotNeeded], true);
    }

    /**
     * The resolutions a person can choose when closing an issue.
     *
     * @return array<int, self>
     */
    public static function closable(): array
    {
        return [self::Repaired, self::FixedByTroubleshooting, self::NotNeeded, self::Replaced];
    }

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_map(fn (self $c) => $c->value, self::cases());
    }
}
