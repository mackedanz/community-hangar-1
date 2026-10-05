<?php

declare(strict_types=1);

namespace Hangar;

/** Filter für den Orga-Hangar. Die Kerndaten stammen aus catalog_items.data (Ship Matrix). */
final class FleetFilter
{
    public const SELECT_FILTERS = ['career', 'role', 'status', 'sizeLabel', 'size'];

    /** @return array{career:?string,role:?string,status:?string,sizeLabel:?string,size:?string,crewMin:int|float|null,crewMax:int|float|null,cargo:int|float|null} */
    public static function parseSpecs(mixed $json): array
    {
        $d = [];
        if (is_array($json)) {
            $d = $json;
        } elseif (is_string($json) && $json !== '') {
            $parsed = json_decode($json, true);
            if (is_array($parsed)) {
                $d = $parsed;
            }
        }
        $str = static fn ($v) => is_string($v) && trim($v) !== '' ? trim($v) : null;
        $num = static fn ($v) => (is_int($v) || is_float($v)) && is_finite((float) $v) ? $v : null;
        // Vereinheitlicht die Schreibweise ("combat" und "Combat" werden zu "Combat").
        $title = static fn (?string $v) => $v === null ? null : mb_strtoupper(mb_substr($v, 0, 1)) . mb_strtolower(mb_substr($v, 1));

        return [
            'career' => $title($str($d['career'] ?? null)),
            'role' => $str($d['role'] ?? null),
            'status' => $str($d['status'] ?? null),
            'sizeLabel' => $str($d['sizeLabel'] ?? null),
            'size' => $str($d['size'] ?? null),
            'crewMin' => $num($d['crewMin'] ?? null),
            'crewMax' => $num($d['crewMax'] ?? null),
            'cargo' => $num($d['cargo'] ?? null),
        ];
    }

    /**
     * @param array<string,mixed> $specs
     * @param array<string,mixed> $f Filter: career, role, status, sizeLabel, size, crewMin (int), crewMax (int)
     */
    public static function matches(array $specs, array $f): bool
    {
        foreach (self::SELECT_FILTERS as $key) {
            if (!empty($f[$key]) && mb_strtolower((string) ($specs[$key] ?? '')) !== mb_strtolower((string) $f[$key])) {
                return false;
            }
        }
        // Schiff bietet Platz für mindestens so viele (Crew max. >= Wert)
        if (isset($f['crewMin']) && ($specs['crewMax'] ?? -1) < $f['crewMin']) {
            return false;
        }
        // Schiff lässt sich mit höchstens so vielen fliegen (Crew min. <= Wert)
        if (isset($f['crewMax']) && ($specs['crewMin'] ?? INF) > $f['crewMax']) {
            return false;
        }
        return true;
    }

    /**
     * Liest die Filter aus den Suchparametern der Seite; ungültige Werte werden ignoriert.
     * @param array<string,mixed> $params
     * @return array<string,mixed>
     */
    public static function parseFilter(array $params): array
    {
        $one = static function (string $k) use ($params): ?string {
            $v = $params[$k] ?? null;
            if (is_array($v)) {
                $v = $v[0] ?? null;
            }
            $v = is_string($v) ? trim($v) : null;
            return $v === '' ? null : $v;
        };
        $int = static function (string $k) use ($one): ?int {
            $v = $one($k);
            if ($v === null || !preg_match('/^\d+$/', $v)) {
                return null;
            }
            $n = (int) $v;
            return $n <= 9999 ? $n : null;
        };
        $f = [];
        foreach (self::SELECT_FILTERS as $key) {
            $v = $one($key);
            if ($v !== null) {
                $f[$key] = $v;
            }
        }
        foreach (['crewMin', 'crewMax'] as $k) {
            $n = $int($k);
            if ($n !== null) {
                $f[$k] = $n;
            }
        }
        return $f;
    }

    /**
     * Einzigartige, sortierte Werte je Auswahlfilter, für die Dropdowns.
     * @param list<array<string,mixed>> $all
     * @return array<string,list<string>>
     */
    public static function options(array $all): array
    {
        $collator = new \Collator('de');
        $out = [];
        foreach (self::SELECT_FILTERS as $key) {
            $vals = [];
            foreach ($all as $s) {
                if (!empty($s[$key])) {
                    $vals[(string) $s[$key]] = true;
                }
            }
            $list = array_keys($vals);
            $collator->sort($list);
            $out[$key] = $list;
        }
        return $out;
    }
}
