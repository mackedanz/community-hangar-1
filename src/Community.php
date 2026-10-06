<?php

declare(strict_types=1);

namespace Hangar;

use DateTimeImmutable;

/**
 * Alle Abfragen, die Daten anderer Nutzer liefern, laufen über diese Klasse und berücksichtigen
 * Orga-Zugehörigkeit und Sichtbarkeit (siehe Visibility). Funktionen mit orgId prüfen zusätzlich,
 * dass der Betrachter Mitglied dieser Orga ist.
 */
final class Community
{
    private static function isMemberOf(string $orgId, ?Viewer $viewer): bool
    {
        return $viewer !== null && in_array($orgId, $viewer->orgIds(), true);
    }

    /**
     * Bildquelle eines Katalogeintrags: Schiffe und Rüstungen kommen aus dem lokalen Bild-Cache, nie direkt
     * von fremden Servern. Rüstungen ohne bekannte Bild-Adresse haben kein Bild.
     */
    public static function imageSrc(string $kind, string $slug, ?string $imageUrl): ?string
    {
        return match ($kind) {
            'SHIP' => '/img/ship/' . rawurlencode($slug),
            'ARMOR' => $imageUrl !== null && $imageUrl !== '' ? '/img/armor/' . rawurlencode($slug) : null,
            default => $imageUrl,
        };
    }

    /**
     * Wer besitzt dieses Katalogobjekt? Nur Nutzer aus gemeinsamen Orgas mit sichtbarem Hangar.
     * @return list<array{userId:string,name:?string,image:?string,quantity:int,lti:bool}>
     */
    public static function getOwners(string $catalogItemId, ?Viewer $viewer): array
    {
        if ($viewer === null) {
            return [];
        }
        [$where, $params] = Visibility::usersWhere('hangar_visibility', $viewer);
        $rows = Db::all(
            "SELECT o.user_id, u.name, u.image, SUM(o.quantity) AS q, MAX(o.lti) AS lti
               FROM owned_items o JOIN users u ON u.id = o.user_id
              WHERE o.catalog_item_id = ? AND $where
              GROUP BY o.user_id, u.name, u.image",
            [$catalogItemId, ...$params],
        );
        $owners = array_map(fn ($r) => [
            'userId' => $r['user_id'], 'name' => $r['name'], 'image' => $r['image'],
            'quantity' => (int) $r['q'], 'lti' => (bool) $r['lti'],
        ], $rows);
        usort($owners, fn ($a, $b) => strcasecmp((string) $a['name'], (string) $b['name']));
        return $owners;
    }

    /**
     * Orga-Hangar: alle Schiffe aller Mitglieder dieser Orga, nur mit Name und Anzahl.
     *
     * Bewusste Ausnahme von der Sichtbarkeitslogik: Die Einstellung "Hangar sichtbar" wird hier
     * NICHT berücksichtigt, weil die Übersicht anonym ist (keine Zuordnung zu Personen). Das steht
     * in den Einstellungen und in der Datenschutzerklärung. Die Funktion liefert deshalb nie
     * Nutzer-IDs oder Namen zurück.
     *
     * @return array{entries:list<array<string,mixed>>,totalShips:int,memberCount:int}
     */
    public static function getOrgFleet(string $orgId, ?Viewer $viewer): array
    {
        $empty = ['entries' => [], 'totalShips' => 0, 'memberCount' => 0];
        if (!self::isMemberOf($orgId, $viewer)) {
            return $empty;
        }
        $inOrg = 'o.user_id IN (SELECT user_id FROM org_memberships WHERE org_id = ?)';

        $catalogGroups = Db::all(
            "SELECT o.catalog_item_id AS cid, SUM(o.quantity) AS q FROM owned_items o
              WHERE o.kind = 'SHIP' AND o.catalog_item_id IS NOT NULL AND $inOrg GROUP BY o.catalog_item_id",
            [$orgId],
        );
        $customGroups = Db::all(
            "SELECT o.custom_name AS n, SUM(o.quantity) AS q FROM owned_items o
              WHERE o.kind = 'SHIP' AND o.catalog_item_id IS NULL AND o.custom_name IS NOT NULL AND $inOrg GROUP BY o.custom_name",
            [$orgId],
        );
        $memberCount = (int) Db::val("SELECT COUNT(DISTINCT o.user_id) FROM owned_items o WHERE o.kind = 'SHIP' AND $inOrg", [$orgId]);

        $ids = array_column($catalogGroups, 'cid');
        $catalog = [];
        if ($ids !== []) {
            foreach (Db::all('SELECT id, kind, slug, name, manufacturer, image_url, data FROM catalog_items WHERE id IN (' . Db::in($ids) . ')', $ids) as $c) {
                $catalog[$c['id']] = $c;
            }
        }

        $entries = [];
        foreach ($catalogGroups as $g) {
            $c = $catalog[$g['cid']] ?? null;
            if ($c === null) {
                continue;
            }
            $entries[] = [
                'catalogItemId' => $c['id'],
                'name' => $c['name'],
                'count' => (int) $g['q'],
                'href' => '/catalog/' . strtolower($c['kind']) . '/' . rawurlencode($c['slug']),
                'manufacturer' => $c['manufacturer'],
                'imageUrl' => self::imageSrc($c['kind'], $c['slug'], $c['image_url']),
                'specs' => FleetFilter::parseSpecs($c['data']),
            ];
        }
        foreach ($customGroups as $g) {
            $entries[] = [
                'catalogItemId' => null, 'name' => $g['n'], 'count' => (int) $g['q'], 'href' => null,
                'manufacturer' => null, 'imageUrl' => null, 'specs' => FleetFilter::parseSpecs(null),
            ];
        }
        usort($entries, fn ($a, $b) => $b['count'] <=> $a['count'] ?: strcasecmp($a['name'], $b['name']));

        return [
            'entries' => $entries,
            'totalShips' => array_sum(array_column($entries, 'count')),
            'memberCount' => $memberCount,
        ];
    }

    /** @return list<array<string,mixed>> */
    public static function listMembers(string $orgId, ?Viewer $viewer): array
    {
        if (!self::isMemberOf($orgId, $viewer)) {
            return [];
        }
        $rows = Db::all(
            'SELECT u.id, COALESCE(m.nick, u.name) AS name, u.image, u.rsi_handle, u.hangar_visibility, u.achievements_visibility, m.role
               FROM org_memberships m JOIN users u ON u.id = m.user_id
              WHERE m.org_id = ? ORDER BY COALESCE(m.nick, u.name) ASC',
            [$orgId],
        );
        if ($rows === []) {
            return [];
        }
        $userIds = array_column($rows, 'id');
        $in = Db::in($userIds);
        $ships = [];
        foreach (Db::all("SELECT user_id, SUM(quantity) q FROM owned_items WHERE kind = 'SHIP' AND user_id IN ($in) GROUP BY user_id", $userIds) as $r) {
            $ships[$r['user_id']] = (int) $r['q'];
        }
        $ach = [];
        foreach (Db::all("SELECT user_id, COUNT(*) c FROM user_achievements WHERE user_id IN ($in) GROUP BY user_id", $userIds) as $r) {
            $ach[$r['user_id']] = (int) $r['c'];
        }
        $orgsOf = [];
        foreach (Db::all("SELECT user_id, org_id FROM org_memberships WHERE user_id IN ($in)", $userIds) as $r) {
            $orgsOf[$r['user_id']][] = $r['org_id'];
        }

        return array_map(function ($u) use ($ships, $ach, $orgsOf, $viewer) {
            $orgIds = $orgsOf[$u['id']] ?? [];
            return [
                'id' => $u['id'], 'name' => $u['name'], 'image' => $u['image'], 'rsiHandle' => $u['rsi_handle'],
                'isAdmin' => $u['role'] === 'ADMIN',
                // null = für den Betrachter nicht sichtbar
                'shipCount' => Visibility::canView($u['hangar_visibility'], $u['id'], $orgIds, $viewer) ? ($ships[$u['id']] ?? 0) : null,
                'achievementCount' => Visibility::canView($u['achievements_visibility'], $u['id'], $orgIds, $viewer) ? ($ach[$u['id']] ?? 0) : null,
            ];
        }, $rows);
    }

    /**
     * Profil eines Nutzers. Gibt null zurück, wenn der Betrachter keine Orga mit ihm teilt, damit
     * Nutzer fremder Orgas nicht einmal als vorhanden erkennbar sind.
     * @return array<string,mixed>|null
     */
    public static function getProfile(string $userId, ?Viewer $viewer): ?array
    {
        if ($viewer === null) {
            return null;
        }
        $user = Db::one('SELECT id, name, image, rsi_handle, hangar_visibility, achievements_visibility FROM users WHERE id = ?', [$userId]);
        if ($user === null) {
            return null;
        }
        $orgIds = array_column(Db::all('SELECT org_id FROM org_memberships WHERE user_id = ?', [$userId]), 'org_id');
        if ($viewer->id !== $user['id'] && !Visibility::sharesOrg($orgIds, $viewer)) {
            return null;
        }
        $showHangar = Visibility::canView($user['hangar_visibility'], $user['id'], $orgIds, $viewer);
        $showAch = Visibility::canView($user['achievements_visibility'], $user['id'], $orgIds, $viewer);

        return [
            'user' => ['id' => $user['id'], 'name' => $user['name'], 'image' => $user['image'], 'rsiHandle' => $user['rsi_handle']],
            'items' => $showHangar ? Hangar::listHangar($userId) : null,
            'achievements' => $showAch ? Db::all(
                'SELECT a.title, a.description, ua.earned_at FROM user_achievements ua JOIN achievements a ON a.id = ua.achievement_id
                  WHERE ua.user_id = ? ORDER BY ua.earned_at ASC',
                [$userId],
            ) : null,
            'lastSync' => $showHangar ? self::getLastSync($userId) : null,
        ];
    }

    /** Zeitpunkt des letzten RSI-Syncs (null, wenn nie synchronisiert). */
    public static function getLastSync(string $userId): ?DateTimeImmutable
    {
        return Time::parse(Db::val('SELECT created_at FROM import_logs WHERE user_id = ? ORDER BY created_at DESC LIMIT 1', [$userId]));
    }

    private const MAX_LISTED_TITLES = 4;

    /**
     * Fasst die Errungenschaften eines Mitglieds vom selben Tag zu einem Feed-Eintrag zusammen.
     * @param list<array{earned_at:string,user_id:string,name:?string,title:string}> $rows
     * @return list<array{at:DateTimeImmutable,userId:string,userName:?string,text:string}>
     */
    public static function groupAchievements(array $rows): array
    {
        $groups = [];
        foreach ($rows as $r) {
            $at = Time::parse($r['earned_at']);
            $key = $r['user_id'] . '|' . $at->format('Y-m-d');
            if (!isset($groups[$key])) {
                $groups[$key] = ['at' => $at, 'userId' => $r['user_id'], 'name' => $r['name'], 'titles' => [$r['title']]];
            } else {
                $groups[$key]['titles'][] = $r['title'];
                if ($at > $groups[$key]['at']) {
                    $groups[$key]['at'] = $at;
                }
            }
        }
        $collator = new \Collator('de');
        $out = [];
        foreach ($groups as $g) {
            $n = count($g['titles']);
            if ($n === 1) {
                $text = "hat die Errungenschaft „{$g['titles'][0]}“ erreicht";
            } else {
                $titles = $g['titles'];
                $collator->sort($titles);
                $shown = implode(', ', array_slice($titles, 0, self::MAX_LISTED_TITLES));
                $rest = $n - self::MAX_LISTED_TITLES;
                $text = "hat $n Errungenschaften erreicht ($shown" . ($rest > 0 ? " und $rest weitere" : '') . ')';
            }
            $out[] = ['at' => $g['at'], 'userId' => $g['userId'], 'userName' => $g['name'], 'text' => $text];
        }
        return $out;
    }

    /**
     * Neueste Aktivitäten der Mitglieder dieser Orga, gefiltert nach Sichtbarkeit.
     * @return list<array{at:DateTimeImmutable,userId:string,userName:?string,text:string}>
     */
    public static function getFeed(string $orgId, ?Viewer $viewer, int $take = 20): array
    {
        if (!self::isMemberOf($orgId, $viewer)) {
            return [];
        }
        [$wAch, $pAch] = Visibility::usersWhere('achievements_visibility', $viewer);
        [$wHan, $pHan] = Visibility::usersWhere('hangar_visibility', $viewer);
        $inOrg = 'u.id IN (SELECT user_id FROM org_memberships WHERE org_id = ?)';

        // Großzügig laden: Mehrere Errungenschaften desselben Tages werden zu einer Zeile.
        $achievements = Db::all(
            "SELECT ua.earned_at, u.id AS user_id, COALESCE(om.nick, u.name) AS name, a.title
               FROM user_achievements ua JOIN users u ON u.id = ua.user_id JOIN achievements a ON a.id = ua.achievement_id
               LEFT JOIN org_memberships om ON om.user_id = u.id AND om.org_id = ?
              WHERE $inOrg AND $wAch ORDER BY ua.earned_at DESC LIMIT " . ($take * 10),
            [$orgId, $orgId, ...$pAch],
        );
        $imports = Db::all(
            "SELECT l.created_at, l.created, u.id AS user_id, COALESCE(om.nick, u.name) AS name
               FROM import_logs l JOIN users u ON u.id = l.user_id
               LEFT JOIN org_memberships om ON om.user_id = u.id AND om.org_id = ?
              WHERE $inOrg AND $wHan ORDER BY l.created_at DESC LIMIT $take",
            [$orgId, $orgId, ...$pHan],
        );

        $events = self::groupAchievements($achievements);
        foreach ($imports as $l) {
            $events[] = [
                'at' => Time::parse($l['created_at']), 'userId' => $l['user_id'], 'userName' => $l['name'],
                'text' => "hat den Hangar mit RSI synchronisiert ({$l['created']} Einträge)",
            ];
        }
        usort($events, fn ($a, $b) => $b['at'] <=> $a['at']);
        return array_slice($events, 0, $take);
    }
}
