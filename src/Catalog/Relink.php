<?php

declare(strict_types=1);

namespace Hangar\Catalog;

use Hangar\Db;
use Hangar\Import\Matcher;

/**
 * Verknüpft Hangar-Einträge, die nur einen freien Namen haben (weil sie beim Import nicht im
 * Katalog standen oder der Katalog gewechselt hat), nachträglich mit dem Katalog.
 */
final class Relink
{
    /** @return int Zahl der neu verknüpften Einträge */
    public static function orphans(): int
    {
        $orphans = Db::all("SELECT id, kind, custom_name FROM owned_items WHERE catalog_item_id IS NULL AND custom_name IS NOT NULL AND kind IN ('SHIP','ARMOR')");
        if ($orphans === []) {
            return 0;
        }
        $rows = Db::all("SELECT id, kind, slug, name, match_key, alt_match_key, code_key, manufacturer FROM catalog_items WHERE kind IN ('SHIP','ARMOR')");
        $index = Matcher::buildIndex($rows);

        $linked = 0;
        foreach ($orphans as $o) {
            $hit = Matcher::find($index, $o['kind'], ['title' => $o['custom_name']]);
            if ($hit === null) {
                continue;
            }
            Db::run('UPDATE owned_items SET catalog_item_id = ?, custom_name = NULL WHERE id = ?', [$hit['id'], $o['id']]);
            $linked++;
        }
        return $linked;
    }
}
