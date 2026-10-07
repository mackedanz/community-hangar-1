<?php

declare(strict_types=1);

namespace Hangar\Controllers;

use Hangar\Auth;
use Hangar\Community;
use Hangar\Db;
use Hangar\DiscordAuthError;
use Hangar\DiscordUnavailableError;
use Hangar\FleetFilter;
use Hangar\Http\Flash;
use Hangar\Http\HttpException;
use Hangar\Http\Request;
use Hangar\Http\Response;
use Hangar\Http\View;
use Hangar\Onboarding;
use Hangar\OrgError;
use Hangar\Orgs;
use Hangar\OrgStats;
use Hangar\RsiOrg;

final class OrgController extends Controller
{
    /**
     * Seite im Orga-Rahmen (Kopf mit Navigation).
     * @param array{id:string,slug:string,name:string,iconUrl:?string,role:string,canPlan:bool} $org
     * @param array<string,mixed> $data
     */
    public static function orgPage(array $org, string $view, array $data, string $title, string $active): Response
    {
        $inner = View::render($view, $data + ['org' => $org], null);
        return self::page('org_frame', ['org' => $org, 'inner' => $inner, 'active' => $active, 'wide' => $active === 'hangar'], $title . ' · ' . $org['name']);
    }

    private static function errorMessage(\Throwable $e): string
    {
        return match (true) {
            $e instanceof OrgError => $e->getMessage(),
            $e instanceof DiscordAuthError => 'Discord-Zugriff abgelaufen. Bitte melde dich ab und neu mit Discord an.',
            $e instanceof DiscordUnavailableError => 'Discord ist gerade nicht erreichbar.',
            default => throw $e,
        };
    }

    // --- Orga anlegen ------------------------------------------------------------------------

    public static function newForm(Request $req): Response
    {
        $viewer = Auth::requireViewer($req);
        $guilds = [];
        $problem = null;
        try {
            $guilds = Orgs::listAdminGuildsForSetup($viewer->id);
        } catch (DiscordAuthError) {
            $problem = 'Discord-Zugriff abgelaufen. Bitte melde dich ab und neu mit Discord an.';
        } catch (DiscordUnavailableError) {
            $problem = 'Discord ist gerade nicht erreichbar. Versuche es gleich noch einmal.';
        }
        return self::page('org_new', ['guilds' => $guilds, 'problem' => $problem, 'form' => self::formValues($req)], 'Orga anlegen');
    }

    /** Rollen-ID-Felder und die danebenstehenden Namensfelder (gleiche Reihenfolge) zu {id: name}. @param mixed $ids @param mixed $names @return array<string,string> */
    public static function roleNames(mixed $ids, mixed $names): array
    {
        $ids = is_array($ids) ? array_values($ids) : [];
        $names = is_array($names) ? array_values($names) : [];
        $out = [];
        foreach ($ids as $i => $id) {
            $id = trim((string) $id);
            if ($id !== '' && trim((string) ($names[$i] ?? '')) !== '') {
                $out[$id] = (string) $names[$i];
            }
        }
        return $out;
    }

    /** Eingabewerte zum Wiederanzeigen nach einem Fehler. @return array<string,mixed> */
    private static function formValues(Request $req): array
    {
        return [];
    }

    public static function create(Request $req): Response
    {
        $viewer = Auth::requireViewer($req);
        try {
            $org = Orgs::create($viewer->id, (string) $req->input('guildId', ''), [
                'name' => (string) $req->input('name', ''),
                'memberRoleIds' => $req->post['memberRoleIds'] ?? [],
                'roleNames' => self::roleNames($req->post['memberRoleIds'] ?? [], $req->post['memberRoleNames'] ?? []),
            ]);
        } catch (\Throwable $e) {
            return Flash::error('/orgs/new', self::errorMessage($e));
        }
        // Mitgliedschaft des Erstellers ist angelegt; Viewer neu laden
        Auth::reset();
        return Response::redirect('/o/' . $org['slug']);
    }

    // --- Orga verwalten ----------------------------------------------------------------------

    public static function settings(Request $req, array $p): Response
    {
        [, $org] = Auth::requireOrgMember($req, (string) $p['slug']);
        if ($org['role'] !== 'ADMIN') {
            throw HttpException::notFound();
        }
        $full = Orgs::find($org['id']) ?? throw HttpException::notFound();
        return self::orgPage($org, 'org_settings', [
            'full' => $full,
            'memberRoleIds' => Orgs::parseRoleIds($full['member_role_ids']),
            'plannerRoleIds' => Orgs::parseRoleIds($full['planner_role_ids']),
            'roleLabels' => Orgs::parseRoleLabels($full['role_labels']),
            'maxRoles' => Orgs::MAX_ROLES,
            'allowed' => Db::all('SELECT discord_id, name, avatar_url, fixed FROM org_allowed_members WHERE org_id = ? ORDER BY name ASC', [$org['id']]),
            'botReady' => \Hangar\DiscordBot::configured(),
            'gate' => Onboarding::gateEnabled(),
            'rsiRoster' => (int) Db::val('SELECT COUNT(*) FROM org_rsi_members WHERE org_id = ?', [$org['id']]),
        ], 'Orga verwalten', 'settings');
    }

    public static function update(Request $req, array $p): Response
    {
        $viewer = Auth::requireViewer($req);
        $orgId = (string) $req->input('orgId', '');
        $slug = (string) Db::val('SELECT slug FROM organizations WHERE id = ?', [$orgId]);
        try {
            Orgs::update($viewer->id, $orgId, [
                'name' => (string) $req->input('name', ''),
                'memberRoleIds' => $req->post['memberRoleIds'] ?? [],
                'plannerRoleIds' => $req->post['plannerRoleIds'] ?? [],
                'roleNames' => self::roleNames($req->post['memberRoleIds'] ?? [], $req->post['memberRoleNames'] ?? [])
                    + self::roleNames($req->post['plannerRoleIds'] ?? [], $req->post['plannerRoleNames'] ?? []),
            ]);
        } catch (\Throwable $e) {
            return Flash::error("/o/$slug/settings", self::errorMessage($e));
        }
        return Flash::ok("/o/$slug/settings", 'Gespeichert.');
    }

    public static function syncAllowlist(Request $req): Response
    {
        $viewer = Auth::requireViewer($req);
        $orgId = (string) $req->input('orgId', '');
        $slug = (string) Db::val('SELECT slug FROM organizations WHERE id = ?', [$orgId]);
        try {
            Orgs::requireOrgAdmin($viewer->id, $orgId);
            $r = Onboarding::syncAllowlist($orgId);
        } catch (\Throwable $e) {
            return Flash::error("/o/$slug/settings", self::errorMessage($e));
        }
        return Flash::ok("/o/$slug/settings", "Zugangsliste abgeglichen: {$r['total']} Mitglieder (+{$r['added']}, −{$r['removed']}).");
    }

    /** RSI-Kürzel der Orga speichern (leer = trennen) und die Mitgliederliste sofort abgleichen. */
    public static function saveRsi(Request $req): Response
    {
        $viewer = Auth::requireViewer($req);
        $orgId = (string) $req->input('orgId', '');
        $slug = (string) Db::val('SELECT slug FROM organizations WHERE id = ?', [$orgId]);
        try {
            Orgs::requireOrgAdmin($viewer->id, $orgId);
            $name = RsiOrg::connect($orgId, (string) $req->input('rsiSid', ''));
            if ($name === null) {
                return Flash::ok("/o/$slug/settings", 'RSI-Orga getrennt.');
            }
            $r = RsiOrg::sync($orgId);
        } catch (\Throwable $e) {
            return Flash::error("/o/$slug/settings", self::errorMessage($e));
        }
        return Flash::ok("/o/$slug/settings", "RSI-Orga „$name“ verbunden: {$r['members']} sichtbare Mitglieder, {$r['redacted']} verborgen.");
    }

    public static function delete(Request $req): Response
    {
        $viewer = Auth::requireViewer($req);
        $orgId = (string) $req->input('orgId', '');
        $slug = (string) Db::val('SELECT slug FROM organizations WHERE id = ?', [$orgId]);
        if ($req->input('confirm') !== 'LÖSCHEN') {
            return Flash::error("/o/$slug/settings", 'Bitte LÖSCHEN eintippen.');
        }
        try {
            Orgs::delete($viewer->id, $orgId);
        } catch (\Throwable $e) {
            return Flash::error("/o/$slug/settings", self::errorMessage($e));
        }
        return Response::redirect('/');
    }

    // --- Orga-Bereich ------------------------------------------------------------------------

    public static function home(Request $req, array $p): Response
    {
        [$viewer, $org] = Auth::requireOrgMember($req, (string) $p['slug']);
        return self::orgPage($org, 'org_home', ['feed' => Community::getFeed($org['id'], $viewer)], 'Übersicht', 'home');
    }

    public static function members(Request $req, array $p): Response
    {
        [$viewer, $org] = Auth::requireOrgMember($req, (string) $p['slug']);
        return self::orgPage($org, 'org_members', [
            'members' => Community::listMembers($org['id'], $viewer),
            'rsiOrgName' => Db::val('SELECT rsi_org_name FROM organizations WHERE id = ?', [$org['id']]),
        ], 'Mitglieder', 'members');
    }

    public static function hangar(Request $req, array $p): Response
    {
        [$viewer, $org] = Auth::requireOrgMember($req, (string) $p['slug']);
        $all = Community::getOrgFleet($org['id'], $viewer);
        $filter = FleetFilter::parseFilter($req->query);
        $options = FleetFilter::options(array_column($all['entries'], 'specs'));
        $entries = array_values(array_filter($all['entries'], fn ($e) => FleetFilter::matches($e['specs'], $filter) && FleetFilter::matchesText($e, $filter['q'] ?? '')));
        return self::orgPage($org, 'org_hangar', [
            'all' => $all, 'filter' => $filter, 'options' => $options, 'entries' => $entries,
            'filtering' => $filter !== [],
            'totalShips' => array_sum(array_column($entries, 'count')),
        ], 'Orga Hangar', 'hangar');
    }

    public static function stats(Request $req, array $p): Response
    {
        [$viewer, $org] = Auth::requireOrgMember($req, (string) $p['slug']);
        $stats = OrgStats::forOrg($org['id'], $viewer) ?? throw HttpException::notFound();
        return self::orgPage($org, 'org_stats', ['stats' => $stats], 'Statistik', 'stats');
    }
}
