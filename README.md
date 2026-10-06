# Community-Hangar – PHP-Version

Inoffizielle Community-Web-App für Star Citizen (PHP 8.3 + MariaDB): Besitz per RSI-Sync erfassen, mit der
eigenen Orga teilen, Events planen, Errungenschaften sammeln. Login über Discord, Onboarding über einen
Discord-Bot. Schiffskatalog aus der **RSI Ship Matrix**
(`https://robertsspaceindustries.com/ship-matrix/index`) mit Schiffsbildern von RSI, Ergänzungen von FleetYards,
Rüstungen aus der Star Citizen Wiki.

## Aufbau

| Pfad | Inhalt |
|---|---|
| `public/index.php` | Front-Controller, `public/js/` Skripte (Vanilla), `public/css/app.css` (gebaut) |
| `src/` | Logik (`Hangar\…`): `Orgs`, `Discord`, `Auth`, `Hangar`, `Community`, `Events`, `Catalog\*`, `Import\*`, … |
| `src/Controllers/` + `views/` | Seiten (PHP-Templates, immer mit `e()` escapen) |
| `src/App.php` | alle Routen |
| `migrations/` | nummerierte SQL-Migrationen (`bin/migrate.php`) |
| `bin/` | `migrate.php`, `catalog-sync.php`, `enrich-items.php`, `warm-images.php`, `migrate-from-sqlite.php` |
| `tests/` | PHPUnit (Datenbank `hangar_test`, nie die Entwicklungsdatenbank) |
| `bookmarklet/rsi-sync.js` | Quelle des RSI-Lesezeichens |
| `Dockerfile`, `docker-compose.yml`, `docker/` | Betrieb (Apache + MariaDB + Caddy) |

## Lokal entwickeln (Windows, ohne Installation)

Die portable Umgebung liegt in `tools/` (PHP, Composer, MariaDB; nicht im Repository).

```powershell
powershell -ExecutionPolicy Bypass -File start-db.ps1   # MariaDB auf Port 3307 starten
powershell -ExecutionPolicy Bypass -File dev.ps1        # Migrationen + Server auf http://localhost:8080
powershell -ExecutionPolicy Bypass -File test.ps1       # PHPUnit
node build-css.mjs                                       # CSS neu bauen (nach Änderungen an views/); vorher einmal: npm install
tools\php\php.exe bin\catalog-sync.php                   # Katalog laden (Ship Matrix, FleetYards, Wiki)
```

Lokale Einstellungen stehen in `.env` (siehe `.env.docker.example`). Mit `APP_ENV=local` gibt es unter
`/dev/login` eine Anmeldung ohne Discord für Tests (nur über localhost; in Produktion nicht gesetzt).

Hinweis zu Netzlaufwerken: Auf einem SMB-Laufwerk meldet PHP neue Dateien als „nicht lesbar“, und InnoDB ist dort
sehr langsam. `test.ps1` setzt die Leserechte, die MariaDB-Daten liegen deshalb auf `C:`.

## Betrieb

```bash
cp .env.docker.example .env      # ausfüllen: DOMAIN, AUTH_DISCORD_ID, AUTH_DISCORD_SECRET, DB_PASSWORD
docker compose up -d
```

Der Container wartet auf die Datenbank, spielt die Migrationen ein und gleicht den Katalog wöchentlich ab
(`CATALOG_SYNC_INTERVAL_SECONDS`). Schiffsbilder werden beim ersten Aufruf eines Schiffs von RSI geladen (erstes Bild der Store-Seite) und im
Volume `ship-images` abgelegt (`/img/ship/{slug}`).

### Übernahme der Daten aus der alten Version (Next.js + SQLite)

```bash
docker compose exec app php bin/catalog-sync.php                      # Katalog zuerst aufbauen
docker cp hangar.db <container>:/tmp/hangar.db                         # KOPIE der alten SQLite-Datei
docker compose exec app php bin/migrate-from-sqlite.php /tmp/hangar.db --dry-run   # nur prüfen
docker compose exec app php bin/migrate-from-sqlite.php /tmp/hangar.db             # übernehmen
```

Sitzungen werden nicht übernommen (alle melden sich einmal neu an), Hangar-Einträge werden über den Namen mit dem neuen
Katalog verknüpft. Schiffe, die es in der Ship Matrix nicht gibt, bleiben als freier Name erhalten.

## Sicherheit

- Anmeldung über Discord (OAuth2 Code-Flow mit `state`), Sitzungs-Cookie `HttpOnly`/`SameSite=Lax`, in der Datenbank nur der Hash.
- Alle POST-Formulare tragen ein CSRF-Token; die Import-API akzeptiert nur Bearer-Token oder Sitzung mit `application/json`.
- Nicht-Mitglieder bekommen für Orga-Seiten 404, `/admin` ist für alle außer `SERVER_ADMIN_DISCORD_ID` ein 404.
- Der Orga-Hangar ist anonym (keine Nutzer-IDs/Namen in der Abfrage).
- Bilder werden nur von einer festen Host-Liste (RSI-Bildspeicher) geladen, geprüft (Typ, Größe) und atomar gespeichert.

## Onboarding-Bot (optional)

Slash-Befehl `/einrichten` als Discord-„Interactions Endpoint“ (`POST /discord/interactions`, Ed25519-signiert, kein
Dauerprozess). Server-ID und aufrufende Person kommen aus der signierten Interaktion; nur Server-Admins dürfen ihn nutzen.

1. Developer Portal → eure Anwendung: *Public Key* → `DISCORD_PUBLIC_KEY`, *Bot → Token* → `DISCORD_BOT_TOKEN`,
   *Server Members Intent* einschalten, *Public Bot* ausschalten, *Interactions Endpoint URL* = `https://<DOMAIN>/discord/interactions`.
2. Bot mit Scope `applications.commands` + `bot` auf den Server einladen (keine Nachrichtenrechte nötig), dann `php bin/register-commands.php`.
3. `/einrichten` im Discord: legt die Orga an, zwei Rollenauswahlen (nutzen / planen, je bis 10), Abgleich schreibt Discord-ID,
   Name und Avatar aller Rolleninhaber in `org_allowed_members`. Danach stündlich (`bin/sync-allowlist.php`) und per Knopf in den Orga-Einstellungen.
   Zusätzlich `/abgleichen`: gleicht sofort ab, darf jede Person mit einer Nutzungs-Rolle der Orga (und Server-Admins); legt nie eine Orga an. Nach dem Update einmal `php bin/register-commands.php` ausführen.
4. `LOGIN_REQUIRES_ALLOWLIST=1`: Wer beim Abgleich auf keiner Zugangsliste mehr steht, dessen Konto wird samt Hangar sofort gelöscht (nicht: Server-Admins, fest eingetragene Personen, leere Mitgliederliste von Discord). Sicherheitsbremse: Verlassen auf einmal mindestens 5 Personen und mehr als die Hälfte der Liste, wird nichts gelöscht; nach Prüfung löscht `php bin/purge-former-members.php --yes` (ohne `--yes` nur eine Vorschau). Signierte Anfragen von Discord, die älter als 5 Minuten sind, werden abgelehnt. Nur wer auf einer Zugangsliste steht (oder `SERVER_ADMIN_DISCORD_ID`) kann sich anmelden; Streichen von der Liste beendet laufende Sitzungen sofort.
   Erst einschalten, wenn die Liste gefüllt ist.

Lokal lässt sich der Endpunkt nur mit einem Tunnel von Discord aus erreichen; die Logik ist mit gefälschten Interaktionen getestet (`tests/OnboardingTest.php`).
