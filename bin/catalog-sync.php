<?php

declare(strict_types=1);

// Gleicht den Katalog ab (Ship Matrix, FleetYards-Ergänzung, Wiki-Rüstungen). Läuft per Cron.
require dirname(__DIR__) . '/vendor/autoload.php';

date_default_timezone_set('UTC');
ini_set('memory_limit', '512M');

try {
    foreach (Hangar\Catalog\Sync::run() as $r) {
        echo sprintf("%-6s geladen %d, gespeichert %d, übersprungen %d%s\n", $r['kind'], $r['fetched'], $r['saved'], $r['skipped'], $r['note'] ? ' (' . $r['note'] . ')' : '');
    }
} catch (Throwable $e) {
    fwrite(STDERR, 'Katalog-Sync fehlgeschlagen: ' . $e->getMessage() . "\n");
    exit(1);
}

// Namenstabelle für die Erkul-Links (optional; bei Fehlern bleibt die bisherige Tabelle)
try {
    echo sprintf("ERKUL  %d Zuordnungen aktualisiert\n", Hangar\Catalog\Erkul::refresh());
} catch (Throwable $e) {
    fwrite(STDERR, 'Erkul-Tabelle nicht aktualisiert: ' . $e->getMessage() . "\n");
}
