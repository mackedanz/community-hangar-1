<?php

declare(strict_types=1);

namespace Hangar;

use Normalizer;

/** Textfunktionen für Abgleich und URLs. */
final class Text
{
    /**
     * Normalisiert einen Namen für den Abgleich: Kleinschreibung, Akzente weg, alles außer
     * Buchstaben und Ziffern entfernt ("Avenger Stalker" = "avenger-stalker").
     */
    public static function normalizeName(string $name): string
    {
        $n = Normalizer::normalize($name, Normalizer::FORM_KD);
        if ($n === false) {
            $n = $name;
        }
        $n = preg_replace('/\p{Mn}+/u', '', $n) ?? $n;
        $n = mb_strtolower($n, 'UTF-8');
        return preg_replace('/[^a-z0-9]+/', '', $n) ?? '';
    }

    /** URL-tauglicher Name; Umlaute werden umschrieben, Standard "orga" bei leerem Ergebnis. */
    public static function slugify(string $name, int $max = 40, string $fallback = 'orga'): string
    {
        $s = mb_strtolower($name, 'UTF-8');
        $s = str_replace(['ä', 'ö', 'ü', 'ß'], ['ae', 'oe', 'ue', 'ss'], $s);
        $n = Normalizer::normalize($s, Normalizer::FORM_KD);
        $s = $n === false ? $s : $n;
        $s = preg_replace('/[^a-z0-9]+/', '-', $s) ?? '';
        $s = trim($s, '-');
        $s = substr($s, 0, $max);
        $s = trim($s, '-');
        return $s === '' ? $fallback : $s;
    }

    /** Erster Buchstabe jedes Wortes groß, Rest klein ("combat" → "Combat"). */
    public static function titleCase(string $s): string
    {
        return mb_convert_case(trim($s), MB_CASE_TITLE, 'UTF-8');
    }
}
