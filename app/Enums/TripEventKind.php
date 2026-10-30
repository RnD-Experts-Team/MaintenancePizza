<?php

namespace App\Enums;

/**
 * Something that happened inside a trip session (docs/MAINTENANCE-V2.md §3).
 *
 * Clocking in and out are not events: they ARE the session. Inside it, each
 * kind opens or closes a span, and each span falls in a BUCKET:
 *   break      unpaid;
 *   travel     paid, driving between places;
 *   parts_run  paid, out buying parts.
 * Whatever time is in no bucket is work.
 */
enum TripEventKind: string
{
    case BreakStart = 'break_start';
    case BreakEnd = 'break_end';
    case TravelStart = 'travel_start';
    case TravelEnd = 'travel_end';
    case PartsRunStart = 'parts_run_start';
    case PartsRunEnd = 'parts_run_end';

    public function label(): string
    {
        return match ($this) {
            self::BreakStart => 'Break started',
            self::BreakEnd => 'Break ended',
            self::TravelStart => 'Started driving',
            self::TravelEnd => 'Stopped driving',
            self::PartsRunStart => 'Went for parts',
            self::PartsRunEnd => 'Back from parts run',
        };
    }

    /**
     * The span this kind belongs to: break, travel or parts_run.
     */
    public function bucket(): string
    {
        return match ($this) {
            self::BreakStart, self::BreakEnd => 'break',
            self::TravelStart, self::TravelEnd => 'travel',
            self::PartsRunStart, self::PartsRunEnd => 'parts_run',
        };
    }

    /**
     * True for the kinds that start a span, false for those that end one.
     */
    public function opens(): bool
    {
        return match ($this) {
            self::BreakStart, self::TravelStart, self::PartsRunStart => true,
            self::BreakEnd, self::TravelEnd, self::PartsRunEnd => false,
        };
    }

    /**
     * The kind that closes the span this one opens, or opens the span this
     * one closes.
     */
    public function counterpart(): self
    {
        return match ($this) {
            self::BreakStart => self::BreakEnd,
            self::BreakEnd => self::BreakStart,
            self::TravelStart => self::TravelEnd,
            self::TravelEnd => self::TravelStart,
            self::PartsRunStart => self::PartsRunEnd,
            self::PartsRunEnd => self::PartsRunStart,
        };
    }

    /**
     * The kind that starts a bucket's span.
     */
    public static function startOf(string $bucket): self
    {
        return match ($bucket) {
            'break' => self::BreakStart,
            'travel' => self::TravelStart,
            'parts_run' => self::PartsRunStart,
        };
    }

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_map(fn (self $c) => $c->value, self::cases());
    }
}
