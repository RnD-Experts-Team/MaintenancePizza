<?php

namespace App\Enums;

/**
 * The kind of closing note attached to a ticket. Generic notes on other
 * entities leave their `type` null; only ticket final notes use this enum.
 */
enum FinalNoteType: string
{
    case FinalNotes = 'final_notes';
    case WhatWeLearned = 'what_we_learned';

    /**
     * Human-readable label for the type.
     */
    public function label(): string
    {
        return match ($this) {
            self::FinalNotes => 'Final Notes',
            self::WhatWeLearned => 'What we learned',
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
