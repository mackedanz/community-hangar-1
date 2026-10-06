<?php

declare(strict_types=1);

namespace Hangar;

use DateTimeImmutable;

/**
 * Orga-Mitgliedschaften. Mitglied einer Orga ist, wer auf ihrem Discord-Server ist und (falls
 * gesetzt) die Mitgliedsrolle hat. Server-Admins zählen immer als Mitglied (Rolle ADMIN).
 * Geprüft wird beim Login und danach spätestens alle 24 Stunden.
 */
final class Orgs
{
    public const CHECK_INTERVAL = 24 * 3600;
    /** So lange bleiben Mitgliedschaften gültig, wenn Discord gestört ist. */
    public const GRACE = 72 * 3600;
    /** Bei einer Discord-Störung frühestens nach dieser Zeit erneut prüfen. */
    private const RETRY = 15 * 60;
    public const MAX_ROLES = 10;

    public static function needsCheck(?DateTimeImmutable $checkedAt, ?DateTimeImmutable $now = null): bool
    {
        $now ??= Time::now();
        return $checkedAt === null || $now->getTimestamp() - $checkedAt->getTimestamp() >= self::CHECK_INTERVAL;
    }

    /** Gleicht die Mitgliedschaften des Nutzers mit Discord ab. Gibt nie Fehler von Discord weiter. */
    public static function syncMemberships(string $userId, ?DateTimeImmutable $now = null): string
    {
        $now ??= Time::now();
        $nowDb = Time::db($now);
        try {
            $token = Discord::accessToken($userId);
            $byGuild = [];
            foreach (Discord::userGuilds($token) as $g) {
                if (isset($g['id'])) {
                    $byGuild[(string) $g['id']] = $g;
                }
            }
            $guildIds = array_keys($byGuild);
            $orgs = $guildIds === []
                ? []
                : Db::all('SELECT * FROM organizations WHERE discord_guild_id IN (' . Db::in($guildIds) . ')', $guildIds);

            $valid = [];
            foreach ($orgs as $org) {
                $admin = Discord::isGuildAdmin($byGuild[$org['discord_guild_id']]);
                $required = self::parseRoleIds($org['member_role_ids']);
                $planners = self::parseRoleIds($org['planner_role_ids']);
                $canPlan = $admin;
                $needRoles = !$admin && ($required !== [] || $planners !== []);
                $member = null;
                try {
                    $member = Discord::member($token, $org['discord_guild_id']);
                } catch (DiscordAuthError | DiscordUnavailableError $e) {
                    // Rollen sind nötig, um den Zugang zu prüfen; der Nickname allein ist es nicht.
                    if ($needRoles) {
                        throw $e;
                    }
                }
                $roles = $member['roles'] ?? null;
                $nick = $member['nick'] ?? null;
                if ($needRoles) {
                    if ($required !== [] && ($roles === null || array_intersect($required, $roles) === [])) {
                        continue;
                    }
                    $canPlan = $roles !== null && array_intersect($planners, $roles) !== [];
                }
                $valid[] = ['orgId' => $org['id'], 'role' => $admin ? 'ADMIN' : 'MEMBER', 'canPlan' => $canPlan, 'nick' => $nick];
            }

            Db::transaction(function () use ($userId, $valid, $nowDb): void {
                $keep = array_column($valid, 'orgId');
                if ($keep === []) {
                    Db::run('DELETE FROM org_memberships WHERE user_id = ?', [$userId]);
                } else {
                    Db::run('DELETE FROM org_memberships WHERE user_id = ? AND org_id NOT IN (' . Db::in($keep) . ')', [$userId, ...$keep]);
                }
                foreach ($valid as $v) {
                    Db::run(
                        'INSERT INTO org_memberships (user_id, org_id, role, can_plan, nick, verified_at) VALUES (?,?,?,?,?,?)
                         ON DUPLICATE KEY UPDATE role = VALUES(role), can_plan = VALUES(can_plan), nick = VALUES(nick), verified_at = VALUES(verified_at)',
                        [$userId, $v['orgId'], $v['role'], $v['canPlan'], $v['nick'], $nowDb],
                    );
                }
                Db::run("UPDATE users SET membership_checked_at = ?, membership_status = 'OK' WHERE id = ?", [$nowDb, $userId]);
            });
            return 'OK';
        } catch (DiscordAuthError) {
            // Fail closed: ohne gültigen Discord-Zugang keine Orga-Daten.
            Db::transaction(function () use ($userId, $nowDb): void {
                Db::run('DELETE FROM org_memberships WHERE user_id = ?', [$userId]);
                Db::run("UPDATE users SET membership_checked_at = ?, membership_status = 'REAUTH' WHERE id = ?", [$nowDb, $userId]);
            });
            return 'REAUTH';
        } catch (DiscordUnavailableError) {
            $graceEnd = Time::db($now->modify('-' . self::GRACE . ' seconds'));
            $retryAt = Time::db($now->modify('-' . (self::CHECK_INTERVAL - self::RETRY) . ' seconds'));
            Db::transaction(function () use ($userId, $graceEnd, $retryAt): void {
                Db::run('DELETE FROM org_memberships WHERE user_id = ? AND verified_at < ?', [$userId, $graceEnd]);
                Db::run("UPDATE users SET membership_checked_at = ?, membership_status = 'UNAVAILABLE' WHERE id = ?", [$retryAt, $userId]);
            });
            return 'UNAVAILABLE';
        }
    }

    /** @return list<array{id:string,slug:string,name:string,iconUrl:?string,role:string,canPlan:bool}> */
    public static function listMyOrgs(string $userId): array
    {
        $rows = Db::all(
            'SELECT o.id, o.slug, o.name, o.icon_url, m.role, m.can_plan
               FROM org_memberships m JOIN organizations o ON o.id = m.org_id
              WHERE m.user_id = ? ORDER BY o.name ASC',
            [$userId],
        );
        return array_map(static fn (array $r): array => [
            'id' => $r['id'],
            'slug' => $r['slug'],
            'name' => $r['name'],
            'iconUrl' => $r['icon_url'],
            'role' => $r['role'],
            'canPlan' => $r['role'] === 'ADMIN' || (bool) $r['can_plan'],
        ], $rows);
    }

    // ---------------------------------------------------------------------------------------
    // Orgas anlegen und verwalten
    // ---------------------------------------------------------------------------------------

    /** Gespeicherte Rollen-IDs (kommagetrennt) als Liste. @return list<string> */
    public static function parseRoleIds(?string $stored): array
    {
        return array_values(array_filter(explode(',', (string) $stored), fn ($s) => $s !== ''));
    }

    /** Gespeicherte Rollennamen (JSON) als Map Rollen-ID → Name. @return array<string,string> */
    public static function parseRoleLabels(mixed $stored): array
    {
        if (!is_string($stored) || $stored === '') {
            return [];
        }
        $v = json_decode($stored, true);
        if (!is_array($v) || array_is_list($v) && $v !== []) {
            return [];
        }
        $out = [];
        foreach ($v as $k => $label) {
            if (is_string($label)) {
                $out[(string) $k] = $label;
            }
        }
        return $out;
    }

    /** Behält nur Namen zu den aktuell eingetragenen Rollen-IDs; leere Namen entfallen. @param list<string> $ids @param array<string,string> $names */
    public static function buildRoleLabels(array $ids, array $names): ?string
    {
        $out = [];
        foreach ($ids as $id) {
            $label = mb_substr(trim((string) ($names[$id] ?? '')), 0, 40);
            if ($label !== '') {
                $out[$id] = $label;
            }
        }
        return $out === [] ? null : json_encode($out, JSON_UNESCAPED_UNICODE);
    }

    /**
     * Prüft und bereinigt die Eingabe. plannerRoleIds/roleNames = null heißt "unverändert".
     * @param array{name?:mixed,memberRoleIds?:mixed,plannerRoleIds?:mixed,roleNames?:mixed} $in
     * @return array{name:string,memberRoleIds:?string,plannerRoleIds:?string,plannerGiven:bool,roleNames:?array<string,string>}
     */
    public static function validateInput(array $in): array
    {
        $name = trim((string) ($in['name'] ?? ''));
        $len = mb_strlen($name);
        if ($len < 2) {
            throw new OrgError('Name zu kurz');
        }
        if ($len > 60) {
            throw new OrgError('Name zu lang');
        }
        $planner = array_key_exists('plannerRoleIds', $in) && $in['plannerRoleIds'] !== null;
        $names = null;
        if (isset($in['roleNames']) && is_array($in['roleNames'])) {
            $names = [];
            foreach ($in['roleNames'] as $k => $v) {
                $names[(string) $k] = (string) $v;
            }
        }
        return [
            'name' => $name,
            'memberRoleIds' => self::cleanRoleIds($in['memberRoleIds'] ?? []),
            'plannerRoleIds' => $planner ? self::cleanRoleIds($in['plannerRoleIds']) : null,
            'plannerGiven' => $planner,
            'roleNames' => $names,
        ];
    }

    /** Leere Felder werden ignoriert, doppelte zusammengefasst. */
    public static function cleanRoleIds(mixed $ids): ?string
    {
        $out = [];
        foreach ((array) $ids as $id) {
            $id = trim((string) $id);
            if ($id === '') {
                continue;
            }
            if (!preg_match('/^\d{17,20}$/', $id)) {
                throw new OrgError('Eine Rollen-ID besteht aus 17 bis 20 Ziffern');
            }
            $out[$id] = true;
        }
        if (count($out) > self::MAX_ROLES) {
            throw new OrgError('Höchstens ' . self::MAX_ROLES . ' Rollen');
        }
        return $out === [] ? null : implode(',', array_keys($out));
    }

    public static function uniqueSlug(string $name): string
    {
        $base = Text::slugify($name);
        for ($i = 1;; $i++) {
            $slug = $i === 1 ? $base : "{$base}-{$i}";
            if (Db::val('SELECT 1 FROM organizations WHERE slug = ?', [$slug]) === null) {
                return $slug;
            }
        }
    }

    /** Server, auf denen der Nutzer Admin ist und die noch keine Orga haben und nicht gesperrt sind. @return list<array<string,mixed>> */
    public static function listAdminGuildsForSetup(string $userId): array
    {
        $guilds = array_values(array_filter(Discord::userGuilds(Discord::accessToken($userId)), [Discord::class, 'isGuildAdmin']));
        $ids = array_map(fn ($g) => (string) $g['id'], $guilds);
        $taken = [];
        if ($ids !== []) {
            $in = Db::in($ids);
            foreach (Db::all("SELECT discord_guild_id AS id FROM organizations WHERE discord_guild_id IN ($in)", $ids) as $r) {
                $taken[$r['id']] = true;
            }
            foreach (Db::all("SELECT discord_guild_id AS id FROM banned_guilds WHERE discord_guild_id IN ($in)", $ids) as $r) {
                $taken[$r['id']] = true;
            }
        }
        $free = array_values(array_filter($guilds, fn ($g) => !isset($taken[(string) $g['id']])));
        usort($free, fn ($a, $b) => strcasecmp((string) $a['name'], (string) $b['name']));
        return $free;
    }

    /**
     * Legt eine Orga an. Der Admin-Status wird dafür live bei Discord geprüft.
     * @param array<string,mixed> $input
     * @return array<string,mixed> die neue Orga
     */
    public static function create(string $userId, string $guildId, array $input): array
    {
        $data = self::validateInput($input);
        if (Db::val('SELECT 1 FROM banned_guilds WHERE discord_guild_id = ?', [$guildId]) !== null) {
            throw new OrgError('Dieser Discord-Server wurde vom Betreiber gesperrt.');
        }
        $guild = null;
        foreach (Discord::userGuilds(Discord::accessToken($userId)) as $g) {
            if ((string) $g['id'] === $guildId) {
                $guild = $g;
                break;
            }
        }
        if ($guild === null || !Discord::isGuildAdmin($guild)) {
            throw new OrgError('Du bist auf diesem Discord-Server kein Admin.');
        }
        if (Db::val('SELECT 1 FROM organizations WHERE discord_guild_id = ?', [$guildId]) !== null) {
            throw new OrgError('Für diesen Discord-Server gibt es schon eine Orga.');
        }

        $id = new_id();
        $labels = self::buildRoleLabels(
            [...self::parseRoleIds($data['memberRoleIds']), ...self::parseRoleIds($data['plannerRoleIds'])],
            $data['roleNames'] ?? [],
        );
        Db::transaction(function () use ($id, $data, $guildId, $guild, $labels, $userId): void {
            Db::insert('organizations', [
                'id' => $id,
                'slug' => self::uniqueSlug($data['name']),
                'name' => $data['name'],
                'discord_guild_id' => $guildId,
                'icon_url' => Discord::guildIconUrl($guild),
                'member_role_ids' => $data['memberRoleIds'],
                'planner_role_ids' => $data['plannerRoleIds'],
                'role_labels' => $labels,
                'created_by_id' => $userId,
            ]);
            Db::insert('org_memberships', ['user_id' => $userId, 'org_id' => $id, 'role' => 'ADMIN', 'can_plan' => 1]);
        });
        return self::find($id) ?? throw new OrgError('Orga konnte nicht angelegt werden.');
    }

    /** @return array<string,mixed>|null */
    public static function find(string $id): ?array
    {
        return Db::one('SELECT * FROM organizations WHERE id = ?', [$id]);
    }

    public static function requireOrgAdmin(string $userId, string $orgId): void
    {
        $role = Db::val('SELECT role FROM org_memberships WHERE user_id = ? AND org_id = ?', [$userId, $orgId]);
        if ($role !== 'ADMIN') {
            throw new OrgError('Nur Admins der Orga dürfen das.');
        }
    }

    /**
     * @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    public static function update(string $userId, string $orgId, array $input): array
    {
        $data = self::validateInput($input);
        self::requireOrgAdmin($userId, $orgId);
        $before = self::find($orgId) ?? throw new OrgError('Orga nicht gefunden.');
        $planner = $data['plannerGiven'] ? $data['plannerRoleIds'] : $before['planner_role_ids'];
        $labels = self::buildRoleLabels(
            [...self::parseRoleIds($data['memberRoleIds']), ...self::parseRoleIds($planner)],
            $data['roleNames'] ?? self::parseRoleLabels($before['role_labels']),
        );
        Db::run(
            'UPDATE organizations SET name = ?, member_role_ids = ?, planner_role_ids = ?, role_labels = ? WHERE id = ?',
            [$data['name'], $data['memberRoleIds'], $planner, $labels, $orgId],
        );
        // Geänderte Rollen: alle anderen Mitglieder beim nächsten Seitenaufruf neu prüfen.
        if ($before['member_role_ids'] !== $data['memberRoleIds'] || $before['planner_role_ids'] !== $planner) {
            Db::run(
                'UPDATE users SET membership_checked_at = NULL
                  WHERE id <> ? AND id IN (SELECT user_id FROM org_memberships WHERE org_id = ?)',
                [$userId, $orgId],
            );
        }
        return self::find($orgId) ?? throw new OrgError('Orga nicht gefunden.');
    }

    /** Löscht die Orga samt Mitgliedschaften. Hangars und Konten der Mitglieder bleiben. */
    public static function delete(string $userId, string $orgId): void
    {
        self::requireOrgAdmin($userId, $orgId);
        Db::run('DELETE FROM organizations WHERE id = ?', [$orgId]);
    }
}
