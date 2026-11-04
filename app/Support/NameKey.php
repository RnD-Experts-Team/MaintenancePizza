<?php

namespace App\Support;

/**
 * The form of a name two records may not share.
 *
 * "Ahmed  Ali", " ahmed ali" and "AHMED ALI" are the same person to whoever
 * reads the list, so they are the same key: lower-cased, trimmed, inner runs
 * of whitespace collapsed to one space. Stored in a `name_key` column under a
 * unique index, so the database itself refuses the second one.
 */
final class NameKey
{
    public static function of(?string $name): string
    {
        $collapsed = preg_replace('/\s+/u', ' ', trim((string) $name)) ?? '';

        return mb_strtolower($collapsed);
    }

    /**
     * The name as it should be stored: trimmed, inner whitespace collapsed,
     * capitalisation left as the person typed it.
     */
    public static function tidy(?string $name): string
    {
        return preg_replace('/\s+/u', ' ', trim((string) $name)) ?? '';
    }
}
