<?php

declare(strict_types=1);

namespace Hangar\Tests;

use DateTimeImmutable;
use Hangar\Db;
use Hangar\Discord;
use Hangar\OrgError;
use Hangar\Orgs;
use Hangar\Text;
use Hangar\Time;

final class OrgsTest extends DbTestCase
{
    private FakeDiscord $discord;

    protected function setUp(): void
    {
        parent::setUp();
        $this->discord = new FakeDiscord();
        $this->discord->install();
    }

    private static int $accSeq = 0;

    /** @return array<string,mixed> */
    private function mkDiscordUser(string $scope = FakeDiscord::SCOPES, int $expiresInSec = 3600): array
    {
        $u = $this->mkUser();
        Db::insert('accounts', [
            'id' => new_id(),
            'user_id' => $u['id'],
            'provider' => 'discord',
            'provider_account_id' => 'acc-' . (++self::$accSeq) . '-' . uniqid(),
            'access_token' => 'alt',
            'refresh_token' => 'ref',
            'expires_at' => time() + $expiresInSec,
            'scope' => $scope,
        ]);
        return $u;
    }

    /** @return array<string,mixed> */
    private function mkOrg(string $guildId, ?string $memberRoleIds = 'role-member', ?string $planner = null): array
    {
        $row = [
            'id' => new_id(), 'slug' => "o-$guildId", 'name' => "Orga $guildId", 'discord_guild_id' => $guildId,
            'member_role_ids' => $memberRoleIds, 'planner_role_ids' => $planner, 'created_by_id' => 'x',
        ];
        Db::insert('organizations', $row);
        return $row;
    }

    /** @return list<string> */
    private function orgsOf(string $userId): array
    {
        $rows = Db::all(
            'SELECT o.discord_guild_id AS g, m.role FROM org_memberships m JOIN organizations o ON o.id = m.org_id WHERE m.user_id = ?',
            [$userId],
        );
        $out = array_map(fn ($r) => "{$r['g']}:{$r['role']}", $rows);
        sort($out);
        return $out;
    }

    private function canPlan(string $userId, string $guildId): bool
    {
        return (bool) Db::val(
            'SELECT m.can_plan FROM org_memberships m JOIN organizations o ON o.id = m.org_id WHERE m.user_id = ? AND o.discord_guild_id = ?',
            [$userId, $guildId],
        );
    }

    // -------------------------------------------------------------------------------------

    public function testIsGuildAdmin(): void
    {
        $g = fn (array $x) => FakeDiscord::guild('1', $x);
        $this->assertTrue(Discord::isGuildAdmin($g(['owner' => true])));
        $this->assertTrue(Discord::isGuildAdmin($g(['permissions' => '8'])));
        $this->assertTrue(Discord::isGuildAdmin($g(['permissions' => '32'])));
        $this->assertTrue(Discord::isGuildAdmin($g(['permissions' => '2147483647'])));
        $this->assertFalse(Discord::isGuildAdmin($g(['permissions' => '1024'])));
        $this->assertFalse(Discord::isGuildAdmin($g(['permissions' => 'kaputt'])));
        // Größer als ein 64-Bit-Integer: die unteren 6 Bits entscheiden (10^6 ist durch 64 teilbar)
        $this->assertTrue(Discord::isGuildAdmin($g(['permissions' => '99999999999999999999999999000008'])));
        $this->assertTrue(Discord::isGuildAdmin($g(['permissions' => '99999999999999999999999999000032'])));
        $this->assertFalse(Discord::isGuildAdmin($g(['permissions' => '99999999999999999999999999000004'])));
    }

    public function testSlugify(): void
    {
        $this->assertSame('explorer-germany', Text::slugify('Explorer Germany'));
        $this->assertSame('groesste-flotte', Text::slugify('Größte Flotte!'));
        $this->assertSame('orga', Text::slugify('!!!'));
    }

    public function testNeedsCheckAfter24Hours(): void
    {
        $now = new DateTimeImmutable('2026-01-02T12:00:00Z');
        $this->assertTrue(Orgs::needsCheck(null, $now));
        $this->assertFalse(Orgs::needsCheck(new DateTimeImmutable('2026-01-02T00:00:00Z'), $now));
        $this->assertTrue(Orgs::needsCheck(new DateTimeImmutable('2026-01-01T12:00:00Z'), $now));
    }

    public function testSyncTakesOnlyServerMembersWithRole(): void
    {
        $this->mkOrg('g-role');
        $this->mkOrg('g-norole');
        $this->mkOrg('g-open', null);
        $this->mkOrg('g-fremd');
        $user = $this->mkDiscordUser();
        $this->discord->guilds = [FakeDiscord::guild('g-role'), FakeDiscord::guild('g-norole'), FakeDiscord::guild('g-open'), FakeDiscord::guild('g-unregistriert')];
        $this->discord->roles = ['g-role' => ['role-member'], 'g-norole' => ['andere-rolle']];

        $this->assertSame('OK', Orgs::syncMemberships($user['id']));
        $this->assertSame(['g-open:MEMBER', 'g-role:MEMBER'], $this->orgsOf($user['id']));
        // Rollen nur für registrierte Orgas mit Rolle, nie für fremde Server
        $calls = implode("\n", $this->discord->calls);
        $this->assertStringNotContainsString('g-unregistriert', $calls);
        $this->assertStringNotContainsString('g-open/member', $calls);

        $u = Db::one('SELECT * FROM users WHERE id = ?', [$user['id']]);
        $this->assertSame('OK', $u['membership_status']);
        $this->assertNotNull($u['membership_checked_at']);
    }

    public function testOneOfSeveralRolesIsEnough(): void
    {
        $this->mkOrg('g-multi', 'rolle-a,rolle-b,rolle-c');
        $withB = $this->mkDiscordUser();
        $withNone = $this->mkDiscordUser();
        $this->discord->guilds = [FakeDiscord::guild('g-multi')];

        $this->discord->roles = ['g-multi' => ['andere', 'rolle-b']];
        Orgs::syncMemberships($withB['id']);
        $this->assertSame(['g-multi:MEMBER'], $this->orgsOf($withB['id']));

        $this->discord->roles = ['g-multi' => ['andere']];
        Orgs::syncMemberships($withNone['id']);
        $this->assertSame([], $this->orgsOf($withNone['id']));
    }

    public function testServerAdminsCountWithoutRole(): void
    {
        $this->mkOrg('g-admin');
        $user = $this->mkDiscordUser();
        $this->discord->guilds = [FakeDiscord::guild('g-admin', ['permissions' => '32'])];
        Orgs::syncMemberships($user['id']);
        $this->assertSame(['g-admin:ADMIN'], $this->orgsOf($user['id']));
    }

    public function testMembershipRemovedWhenServerLeftOrRoleRevoked(): void
    {
        $this->mkOrg('g-leave');
        $this->mkOrg('g-demote');
        $user = $this->mkDiscordUser();
        $this->discord->guilds = [FakeDiscord::guild('g-leave'), FakeDiscord::guild('g-demote')];
        $this->discord->roles = ['g-leave' => ['role-member'], 'g-demote' => ['role-member']];
        Orgs::syncMemberships($user['id']);
        $this->assertSame(['g-demote:MEMBER', 'g-leave:MEMBER'], $this->orgsOf($user['id']));

        $this->discord->guilds = [FakeDiscord::guild('g-demote')];
        $this->discord->roles = ['g-demote' => []];
        Orgs::syncMemberships($user['id']);
        $this->assertSame([], $this->orgsOf($user['id']));
    }

    public function testFailClosedOnInvalidDiscordAccess(): void
    {
        $this->mkOrg('g-401', null);
        $user = $this->mkDiscordUser();
        $this->discord->guilds = [FakeDiscord::guild('g-401')];
        Orgs::syncMemberships($user['id']);
        $this->assertCount(1, $this->orgsOf($user['id']));

        $this->discord->guildsStatus = 401;
        $this->assertSame('REAUTH', Orgs::syncMemberships($user['id']));
        $this->assertSame([], $this->orgsOf($user['id']));
        $this->assertSame('REAUTH', Db::val('SELECT membership_status FROM users WHERE id = ?', [$user['id']]));
    }

    public function testNewLoginRequiredWhenScopesMissing(): void
    {
        $user = $this->mkDiscordUser('identify');
        $this->assertSame('REAUTH', Orgs::syncMemberships($user['id']));
        $this->assertSame([], $this->discord->calls);
    }

    public function testKeepsMembershipsDuringOutageUpTo72HoursAndRetriesSoon(): void
    {
        $this->mkOrg('g-500a', null);
        $b = $this->mkOrg('g-500b', null);
        $user = $this->mkDiscordUser();
        $this->discord->guilds = [FakeDiscord::guild('g-500a'), FakeDiscord::guild('g-500b')];
        Orgs::syncMemberships($user['id']);
        // g-500b wurde zuletzt vor mehr als 72 Stunden bestätigt
        Db::run('UPDATE org_memberships SET verified_at = ? WHERE user_id = ? AND org_id = ?', [
            Time::db(Time::now()->modify('-' . (Orgs::GRACE + 1) . ' seconds')), $user['id'], $b['id'],
        ]);

        $this->discord->guildsStatus = 502;
        $now = Time::now();
        $this->assertSame('UNAVAILABLE', Orgs::syncMemberships($user['id'], $now));
        $this->assertSame(['g-500a:MEMBER'], $this->orgsOf($user['id']));

        $checkedAt = Time::parse(Db::val('SELECT membership_checked_at FROM users WHERE id = ?', [$user['id']]));
        $this->assertFalse(Orgs::needsCheck($checkedAt, $now->modify('+5 minutes')));
        $this->assertTrue(Orgs::needsCheck($checkedAt, $now->modify('+16 minutes')));
    }

    public function testRefreshesExpiredTokenAndStoresIt(): void
    {
        $user = $this->mkDiscordUser(FakeDiscord::SCOPES, -10);
        Orgs::syncMemberships($user['id']);
        $this->assertMatchesRegularExpression('#oauth2/token$#', $this->discord->calls[0]);
        $acc = Db::one('SELECT * FROM accounts WHERE user_id = ?', [$user['id']]);
        $this->assertSame('neu', $acc['access_token']);
        $this->assertSame('ref2', $acc['refresh_token']);
        $this->assertGreaterThan(time(), (int) $acc['expires_at']);
    }

    public function testNewLoginRequiredWhenRefreshIsRejected(): void
    {
        $user = $this->mkDiscordUser(FakeDiscord::SCOPES, -10);
        $this->discord->tokenStatus = 400;
        $this->assertSame('REAUTH', Orgs::syncMemberships($user['id']));
    }

    // --- Orga anlegen und verwalten ----------------------------------------------------------

    public function testRejectsNonAdmins(): void
    {
        $user = $this->mkDiscordUser();
        $this->discord->guilds = [FakeDiscord::guild('g-new-1', ['permissions' => '1024'])];
        $this->expectException(OrgError::class);
        Orgs::create($user['id'], 'g-new-1', ['name' => 'Test', 'memberRoleIds' => ['']]);
    }

    public function testRejectsServersTheUserIsNotOn(): void
    {
        $user = $this->mkDiscordUser();
        $this->expectException(OrgError::class);
        Orgs::create($user['id'], 'g-fremd-2', ['name' => 'Test', 'memberRoleIds' => ['']]);
    }

    public function testCreatesOrgMakesCreatorAdminAndPreventsDuplicates(): void
    {
        $user = $this->mkDiscordUser();
        $this->discord->guilds = [FakeDiscord::guild('g-new-2', ['owner' => true, 'icon' => 'abc'])];

        $org = Orgs::create($user['id'], 'g-new-2', ['name' => 'Explorer Germany', 'memberRoleIds' => ['123456789012345678']]);
        $this->assertMatchesRegularExpression('/^explorer-germany/', $org['slug']);
        $this->assertSame('https://cdn.discordapp.com/icons/g-new-2/abc.png', $org['icon_url']);
        $this->assertSame(['g-new-2:ADMIN'], $this->orgsOf($user['id']));

        $this->expectException(OrgError::class);
        Orgs::create($user['id'], 'g-new-2', ['name' => 'Nochmal', 'memberRoleIds' => ['']]);
    }

    public function testStoresUpToTenRolesIgnoringEmptyAndDuplicates(): void
    {
        $user = $this->mkDiscordUser();
        $this->discord->guilds = [FakeDiscord::guild('g-new-4', ['owner' => true])];
        $a = '111111111111111111';
        $b = '222222222222222222';
        $org = Orgs::create($user['id'], 'g-new-4', ['name' => 'Mehrrollen', 'memberRoleIds' => [$a, '', $b, $a, '']]);
        $this->assertSame("$a,$b", $org['member_role_ids']);

        $this->discord->guilds = [FakeDiscord::guild('g-new-5', ['owner' => true])];
        $eleven = array_map(fn ($d) => str_repeat((string) ($d % 10), 18), range(1, 11));
        $eleven[9] = '1010101010101010101';
        $eleven[10] = '2020202020202020202';
        $this->expectException(OrgError::class);
        Orgs::create($user['id'], 'g-new-5', ['name' => 'Zu viele', 'memberRoleIds' => $eleven]);
    }

    public function testRoleIdFormatIsChecked(): void
    {
        $user = $this->mkDiscordUser();
        $this->discord->guilds = [FakeDiscord::guild('g-new-3', ['owner' => true])];
        $this->expectException(OrgError::class);
        Orgs::create($user['id'], 'g-new-3', ['name' => 'Test', 'memberRoleIds' => ['Mitglied']]);
    }

    public function testBannedGuildCannotBeCreatedAndNoDiscordCallIsMade(): void
    {
        $user = $this->mkDiscordUser();
        Db::insert('banned_guilds', ['discord_guild_id' => 'g-ban', 'name' => 'X', 'banned_by_id' => 'a']);
        try {
            Orgs::create($user['id'], 'g-ban', ['name' => 'Test', 'memberRoleIds' => []]);
            $this->fail('Sperre nicht beachtet');
        } catch (OrgError $e) {
            $this->assertStringContainsString('gesperrt', $e->getMessage());
        }
        $this->assertSame([], $this->discord->calls);
    }

    public function testOnlyAdminsChangeOrDeleteAndNewRoleForcesRecheck(): void
    {
        $org = $this->mkOrg('g-manage');
        $admin = $this->mkDiscordUser();
        $member = $this->mkDiscordUser();
        Db::insert('org_memberships', ['user_id' => $admin['id'], 'org_id' => $org['id'], 'role' => 'ADMIN']);
        Db::insert('org_memberships', ['user_id' => $member['id'], 'org_id' => $org['id'], 'role' => 'MEMBER']);
        Db::run('UPDATE users SET membership_checked_at = NOW() WHERE id = ?', [$member['id']]);

        $input = ['name' => 'Neu', 'memberRoleIds' => ['999999999999999999']];
        try {
            Orgs::update($member['id'], $org['id'], $input);
            $this->fail('Mitglied durfte ändern');
        } catch (OrgError) {
        }
        try {
            Orgs::delete($member['id'], $org['id']);
            $this->fail('Mitglied durfte löschen');
        } catch (OrgError) {
        }

        Orgs::update($admin['id'], $org['id'], $input);
        $this->assertSame('999999999999999999', Db::val('SELECT member_role_ids FROM organizations WHERE id = ?', [$org['id']]));
        $this->assertNull(Db::val('SELECT membership_checked_at FROM users WHERE id = ?', [$member['id']]));

        Orgs::delete($admin['id'], $org['id']);
        $this->assertNull(Db::val('SELECT id FROM organizations WHERE id = ?', [$org['id']]));
        $this->assertSame(0, (int) Db::val('SELECT COUNT(*) FROM org_memberships WHERE org_id = ?', [$org['id']]));
        $this->assertNotNull(Db::val('SELECT id FROM users WHERE id = ?', [$member['id']]));
    }

    // --- Planer-Rolle ------------------------------------------------------------------------

    public function testCanPlanForAdminsAndPlannerRoleOnly(): void
    {
        $this->mkOrg('g-plan', 'role-member', 'role-plan,role-plan2');
        $planer = $this->mkDiscordUser();
        $normal = $this->mkDiscordUser();
        $admin = $this->mkDiscordUser();

        $this->discord->guilds = [FakeDiscord::guild('g-plan')];
        $this->discord->roles = ['g-plan' => ['role-member', 'role-plan2']];
        Orgs::syncMemberships($planer['id']);
        $this->discord->roles = ['g-plan' => ['role-member']];
        Orgs::syncMemberships($normal['id']);
        $this->discord->guilds = [FakeDiscord::guild('g-plan', ['permissions' => '8'])];
        $this->discord->roles = ['g-plan' => []];
        Orgs::syncMemberships($admin['id']);

        $this->assertTrue($this->canPlan($planer['id'], 'g-plan'));
        $this->assertFalse($this->canPlan($normal['id'], 'g-plan'));
        $this->assertTrue($this->canPlan($admin['id'], 'g-plan'));
    }

    public function testFetchesRolesEvenWhenOnlyPlannerRoleIsSet(): void
    {
        $this->mkOrg('g-plan-open', null, 'role-plan');
        $planer = $this->mkDiscordUser();
        $normal = $this->mkDiscordUser();
        $this->discord->guilds = [FakeDiscord::guild('g-plan-open')];
        $this->discord->roles = ['g-plan-open' => ['role-plan']];
        Orgs::syncMemberships($planer['id']);
        $this->discord->roles = ['g-plan-open' => []];
        Orgs::syncMemberships($normal['id']);

        $this->assertSame(['g-plan-open:MEMBER'], $this->orgsOf($planer['id']));
        $this->assertTrue($this->canPlan($planer['id'], 'g-plan-open'));
        $this->assertSame(['g-plan-open:MEMBER'], $this->orgsOf($normal['id']));
        $this->assertFalse($this->canPlan($normal['id'], 'g-plan-open'));
    }

    public function testPlannerRolesAreStoredAndForceRecheck(): void
    {
        $admin = $this->mkDiscordUser();
        $member = $this->mkDiscordUser();
        $org = $this->mkOrg('g-plan-edit', null);
        Db::insert('org_memberships', ['user_id' => $admin['id'], 'org_id' => $org['id'], 'role' => 'ADMIN']);
        Db::insert('org_memberships', ['user_id' => $member['id'], 'org_id' => $org['id']]);
        Db::run('UPDATE users SET membership_checked_at = NOW() WHERE id = ?', [$member['id']]);

        Orgs::update($admin['id'], $org['id'], ['name' => 'Edit', 'memberRoleIds' => [], 'plannerRoleIds' => ['111111111111111111']]);
        $this->assertSame('111111111111111111', Db::val('SELECT planner_role_ids FROM organizations WHERE id = ?', [$org['id']]));
        $this->assertNull(Db::val('SELECT membership_checked_at FROM users WHERE id = ?', [$member['id']]));

        // Ohne Angabe bleiben die Planer-Rollen unverändert
        Orgs::update($admin['id'], $org['id'], ['name' => 'Edit 2', 'memberRoleIds' => []]);
        $this->assertSame('111111111111111111', Db::val('SELECT planner_role_ids FROM organizations WHERE id = ?', [$org['id']]));
    }

    public function testRoleLabelsOnlyForEnteredRolesAndKeptWithoutInput(): void
    {
        $admin = $this->mkDiscordUser();
        $org = $this->mkOrg('g-labels', null);
        Db::insert('org_memberships', ['user_id' => $admin['id'], 'org_id' => $org['id'], 'role' => 'ADMIN']);
        $a = '111111111111111111';
        $b = '222222222222222222';
        $read = fn () => Orgs::parseRoleLabels(Db::val('SELECT role_labels FROM organizations WHERE id = ?', [$org['id']]));

        Orgs::update($admin['id'], $org['id'], [
            'name' => 'Labels', 'memberRoleIds' => [$a], 'plannerRoleIds' => [$b],
            'roleNames' => [$a => ' Mitglied ', $b => 'Planer', '333333333333333333' => 'Fremd'],
        ]);
        $this->assertSame([$a => 'Mitglied', $b => 'Planer'], $read());

        // Ohne roleNames bleiben die Namen; entfernte Rollen verlieren ihren Namen
        Orgs::update($admin['id'], $org['id'], ['name' => 'Labels', 'memberRoleIds' => [$a], 'plannerRoleIds' => []]);
        $this->assertSame([$a => 'Mitglied'], $read());
    }
}
