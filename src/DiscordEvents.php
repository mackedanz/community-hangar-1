<?php

declare(strict_types=1);

namespace Hangar;

use DateTimeImmutable;

/**
 * Übernimmt die geplanten Server-Events eines Discord-Servers als Termine der Orga (nur Discord → Hangar).
 * Läuft per Cron (bin/discord-events.php) und nach dem Speichern der Einstellung. Schiffe, Plätze und Zusagen
 * gehören nur dem Hangar und werden nie angefasst; Titel, Zeit, Ort und Beschreibung folgen Discord, solange
 * kein Planer sie im Hangar geändert hat.
 */
final class DiscordEvents
{
    public const MODES = ['OFF', 'DRAFT', 'PUBLISHED'];

    /** Discord-Status eines Server-Events. */
    private const SCHEDULED = 1;
    private const ACTIVE = 2;
    private const CANCELED = 4;

    /** @return array<string,string> Ergebnis je Orga (Kürzel => Text) */
    public static function syncAll(): array
    {
        $out = [];
        foreach (Db::all("SELECT * FROM organizations WHERE discord_events_mode <> 'OFF'") as $org) {
            try {
                $out[$org['slug']] = self::syncOrg($org);
            } catch (DiscordAuthError | DiscordUnavailableError $e) {
                $out[$org['slug']] = 'Fehler: ' . $e->getMessage();
            }
        }
        return $out;
    }

    /** @param array<string,mixed> $org Zeile aus organizations */
    public static function syncOrg(array $org): string
    {
        $list = DiscordBot::scheduledEvents((string) $org['discord_guild_id']);
        $r = self::apply($org, $list);
        Db::run('UPDATE organizations SET discord_events_synced_at = ? WHERE id = ?', [Time::nowDb(), $org['id']]);
        return 'Discord meldet ' . count($list) . (count($list) === 1 ? ' Event' : ' Events') . ": {$r['created']} neu, {$r['updated']} geändert, {$r['cancelled']} abgesagt";
    }

    /**
     * @param array<string,mixed> $org
     * @param list<array{id:string,name:string,description:?string,location:?string,startsAt:DateTimeImmutable,endsAt:?DateTimeImmutable,status:int,creatorId:?string}> $list
     * @return array{created:int,updated:int,cancelled:int}
     */
    public static function apply(array $org, array $list, ?DateTimeImmutable $now = null): array
    {
        $orgId = (string) $org['id'];
        $newStatus = ($org['discord_events_mode'] ?? 'OFF') === 'PUBLISHED' ? 'PLANNED' : 'DRAFT';
        $r = ['created' => 0, 'updated' => 0, 'cancelled' => 0];
        $seen = [];
        foreach ($list as $d) {
            $seen[$d['id']] = true;
            $row = Db::one('SELECT * FROM events WHERE org_id = ? AND discord_event_id = ?', [$orgId, $d['id']]);
            $live = $d['status'] === self::SCHEDULED || $d['status'] === self::ACTIVE;
            $fields = [
                'title' => self::title($d['name']),
                'description' => self::cut($d['description'], 3000),
                'location' => self::cut($d['location'], 100),
                'starts_at' => Time::db($d['startsAt']),
                'ends_at' => $d['endsAt'] !== null && $d['endsAt'] > $d['startsAt'] ? Time::db($d['endsAt']) : null,
            ];
            if ($row === null) {
                if (!$live) {
                    continue;   // Abgesagtes und Beendetes wird nie neu übernommen
                }
                Db::insert('events', [
                    'id' => new_id(), 'org_id' => $orgId, 'status' => $newStatus, 'discord_event_id' => $d['id'],
                    'created_by_id' => self::creator($orgId, $d['creatorId'], (string) $org['created_by_id']),
                ] + $fields);
                $r['created']++;
                continue;
            }
            if ($d['status'] === self::CANCELED) {
                if ($row['status'] !== 'CANCELLED') {
                    Db::run("UPDATE events SET status = 'CANCELLED' WHERE id = ?", [$row['id']]);
                    $r['cancelled']++;
                }
                continue;
            }
            if (!$live || $row['discord_edited']) {
                continue;
            }
            $changed = false;
            foreach ($fields as $k => $v) {
                if ((string) $row[$k] !== (string) $v) {
                    $changed = true;
                }
            }
            $reopen = $row['status'] === 'CANCELLED';
            if ($changed || $reopen) {
                Db::run(
                    'UPDATE events SET title = ?, description = ?, location = ?, starts_at = ?, ends_at = ?' . ($reopen ? ', status = ?' : '') . ' WHERE id = ?',
                    [$fields['title'], $fields['description'], $fields['location'], $fields['starts_at'], $fields['ends_at'], ...($reopen ? [$newStatus] : []), $row['id']],
                );
                $r['updated']++;
            }
        }
        // In Discord gelöscht: ein künftiger, unveränderter Termin, der nicht mehr in der Liste steht, gilt als abgesagt.
        foreach (Db::all(
            "SELECT id, discord_event_id FROM events WHERE org_id = ? AND discord_event_id IS NOT NULL AND status <> 'CANCELLED'
                AND discord_edited = 0 AND starts_at > ?",
            [$orgId, Time::db($now ?? Time::now())],
        ) as $e) {
            if (!isset($seen[$e['discord_event_id']])) {
                Db::run("UPDATE events SET status = 'CANCELLED' WHERE id = ?", [$e['id']]);
                $r['cancelled']++;
            }
        }
        return $r;
    }

    private static function title(string $name): string
    {
        $t = trim(mb_substr($name, 0, 100));
        return mb_strlen($t) < 2 ? 'Discord-Event' : $t;
    }

    private static function cut(?string $s, int $max): ?string
    {
        $s = trim((string) $s);
        return $s === '' ? null : mb_substr($s, 0, $max);
    }

    /** Der Ersteller des Discord-Events, wenn er die App nutzt; sonst der Ersteller der Orga. */
    private static function creator(string $orgId, ?string $discordId, string $fallback): string
    {
        if ($discordId !== null) {
            $id = Db::val(
                'SELECT u.id FROM users u JOIN org_memberships m ON m.user_id = u.id AND m.org_id = ? WHERE u.discord_id = ?',
                [$orgId, $discordId],
            );
            if (is_string($id)) {
                return $id;
            }
        }
        return $fallback;
    }
}