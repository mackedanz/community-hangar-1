<?php

declare(strict_types=1);

namespace Hangar\Tests;

use Hangar\Community;
use Hangar\Db;
use Hangar\Http\Client;
use Hangar\OrgError;
use Hangar\RsiOrg;

/** RSI-Orga: Handle aus dem Nickname, Abgleich der öffentlichen Mitgliederliste, Rahmenfarbe in der Mitgliederliste. */
final class RsiOrgTest extends DbTestCase
{
    private string $orgId;
    /** @var list<array{0:?string,1:bool}> Mitglieder der nachgebauten RSI-Orga: [Handle (null = verborgen), Hauptorga] */
    private array $roster = [];
    private bool $rsiDown = false;
    private int $requests = 0;

    protected function setUp(): void
    {
        parent::setUp();
        RsiOrg::pause(0);
        $this->orgId = new_id();
        Db::insert('organizations', ['id' => $this->orgId, 'slug' => 'exp', 'name' => 'Exp', 'discord_guild_id' => '900000000000000009', 'created_by_id' => 'x']);
        $this->roster = [['eXpG_McDance', true], ['Pilot_1', true], ['Helfer', false]];
        $this->rsiDown = false;
        $this->requests = 0;
        Client::fake(function (string $method, string $url, array $h, ?string $body): array {
            $this->requests++;
            if ($this->rsiDown || !str_contains($url, '/api/orgs/getOrgMembers')) {
                return ['status' => 503, 'body' => '', 'headers' => []];
            }
            $q = json_decode((string) $body, true);
            if (($q['symbol'] ?? '') !== 'EXPG') {
                return ['status' => 200, 'body' => json_encode(['success' => 0, 'code' => 'ErrNoOrg', 'msg' => 'x']), 'headers' => []];
            }
            $slice = array_slice($this->roster, ($q['page'] - 1) * $q['pagesize'], $q['pagesize']);
            $html = '';
            foreach ($slice as [$handle, $main]) {
                $cls = 'member-item js-member-item ' . ($main ? 'org-main' : 'org-affiliate') . ($handle === null ? ' org-visibility-R' : ' org-visibility-V');
                $link = $handle === null ? '<a class="membercard js-edit-member" >' : '<a class="membercard js-edit-member"  href="/citizens/' . $handle . '">';
                $html .= "\t<li class=\"$cls\" data-org-sid=\"EXPG\" data-org-name=\"&#x2b50;Explorer-Germany&amp;Co\">\n\t\t$link</a></li>\n";
            }
            return ['status' => 200, 'body' => json_encode(['success' => 1, 'code' => 'OK', 'data' => ['totalrows' => count($this->roster), 'html' => $html]]), 'headers' => []];
        });
    }

    public function testHandleFromName(): void
    {
        $this->assertSame('eXpG_McDance', RsiOrg::handleFromName('eXpG_McDance (Micha)'));
        $this->assertSame('eXpG_McDance', RsiOrg::handleFromName('  eXpG_McDance  '));
        $this->assertSame('Pilot-2', RsiOrg::handleFromName('Pilot-2 [Anna]'));
        $this->assertNull(RsiOrg::handleFromName('Micha der Große'));
        $this->assertNull(RsiOrg::handleFromName('(nur Klammer)'));
        $this->assertNull(RsiOrg::handleFromName(null));
    }

    public function testCleanSid(): void
    {
        $this->assertSame('EXPG', RsiOrg::cleanSid(' expg '));
        $this->assertNull(RsiOrg::cleanSid('E'));
        $this->assertNull(RsiOrg::cleanSid('EX PG'));
        $this->assertNull(RsiOrg::cleanSid('../x'));
    }

    public function testConnectChecksTheOrgAndStoresNameAndSid(): void
    {
        $name = RsiOrg::connect($this->orgId, 'expg');
        $this->assertSame('⭐Explorer-Germany&Co', $name);
        $o = Db::one('SELECT rsi_sid, rsi_org_name, rsi_synced_at FROM organizations WHERE id = ?', [$this->orgId]);
        $this->assertSame('EXPG', $o['rsi_sid']);
        $this->assertSame('⭐Explorer-Germany&Co', $o['rsi_org_name']);
        $this->assertNull($o['rsi_synced_at'], 'verbinden allein gleicht noch nicht ab');
    }

    public function testUnknownOrgIsRejectedAndNothingStored(): void
    {
        $this->expectException(OrgError::class);
        try {
            RsiOrg::connect($this->orgId, 'NOPE');
        } finally {
            $this->assertNull(Db::val('SELECT rsi_sid FROM organizations WHERE id = ?', [$this->orgId]));
        }
    }

    public function testInvalidSidIsRejectedWithoutRequest(): void
    {
        try {
            RsiOrg::connect($this->orgId, 'a b!');
            $this->fail('OrgError erwartet');
        } catch (OrgError) {
            $this->assertSame(0, $this->requests);
        }
    }

    public function testSyncReadsAllPagesAndCountsHiddenMembers(): void
    {
        $this->roster = [];
        for ($i = 1; $i <= 40; $i++) {
            $this->roster[] = ["Pilot_$i", $i !== 40];
        }
        $this->roster[] = [null, true];
        RsiOrg::connect($this->orgId, 'EXPG');
        $r = RsiOrg::sync($this->orgId);
        $this->assertSame(['members' => 40, 'redacted' => 1], $r);
        $this->assertSame(0, (int) Db::val('SELECT main FROM org_rsi_members WHERE org_id = ? AND handle = ?', [$this->orgId, 'pilot_40']));
        $this->assertSame(1, (int) Db::val('SELECT rsi_redacted FROM organizations WHERE id = ?', [$this->orgId]));
        $this->assertNotNull(Db::val('SELECT rsi_synced_at FROM organizations WHERE id = ?', [$this->orgId]));
    }

    public function testFailedSyncKeepsOldRoster(): void
    {
        RsiOrg::connect($this->orgId, 'EXPG');
        RsiOrg::sync($this->orgId);
        $this->rsiDown = true;
        try {
            RsiOrg::sync($this->orgId);
            $this->fail('OrgError erwartet');
        } catch (OrgError) {
        }
        $this->assertSame(3, (int) Db::val('SELECT COUNT(*) FROM org_rsi_members WHERE org_id = ?', [$this->orgId]));
    }

    public function testChangingSidDropsOldRosterAndEmptyDisconnects(): void
    {
        RsiOrg::connect($this->orgId, 'EXPG');
        RsiOrg::sync($this->orgId);
        $this->assertNull(RsiOrg::connect($this->orgId, ''));
        $this->assertSame(0, (int) Db::val('SELECT COUNT(*) FROM org_rsi_members WHERE org_id = ?', [$this->orgId]));
        $this->assertNull(Db::val('SELECT rsi_sid FROM organizations WHERE id = ?', [$this->orgId]));
    }

    public function testSyncAllSkipsOrgsWithoutSidAndReportsErrors(): void
    {
        $this->assertSame([], RsiOrg::syncAll());
        RsiOrg::connect($this->orgId, 'EXPG');
        $this->assertStringContainsString('3 Mitglieder', RsiOrg::syncAll()['exp']);
        $this->rsiDown = true;
        $this->assertStringStartsWith('Fehler:', RsiOrg::syncAll()['exp']);
    }

    public function testStatusMatrix(): void
    {
        $roster = ['expg_mcdance' => true, 'helfer' => false];
        $this->assertSame('main', RsiOrg::status(['eXpG_McDance'], $roster, 0));
        $this->assertSame('affiliate', RsiOrg::status([null, 'HELFER'], $roster, 0));
        $this->assertSame('out', RsiOrg::status(['Fremder'], $roster, 0));
        $this->assertSame('unknown', RsiOrg::status(['Fremder'], $roster, 2), 'verborgene Mitglieder: nicht als „nicht drin“ behaupten');
        $this->assertSame('unknown', RsiOrg::status([null, null], $roster, 0), 'ohne Handle nichts behaupten');
    }

    public function testMemberListShowsStatusPerMemberOnlyAfterSync(): void
    {
        $mk = function (string $name, ?string $nick, ?string $handle = null): string {
            $u = $this->mkUser(['name' => $name, 'rsi_handle' => $handle]);
            Db::insert('org_memberships', ['user_id' => $u['id'], 'org_id' => $this->orgId, 'role' => 'MEMBER', 'can_plan' => 0, 'nick' => $nick]);
            return $u['id'];
        };
        $mc = $mk('Micha', 'eXpG_McDance (Micha)');
        $mk('Anna', 'Pilot_1');
        $mk('Ben', null, 'helfer');
        $mk('Cem Müller', 'Cem der Große');
        $viewer = VisibilityFilterTest::viewer($mc, [$this->orgId]);

        $before = Community::listMembers($this->orgId, $viewer);
        $this->assertSame([null], array_values(array_unique(array_column($before, 'rsiStatus'))));

        RsiOrg::connect($this->orgId, 'EXPG');
        $this->assertSame([null], array_values(array_unique(array_column(Community::listMembers($this->orgId, $viewer), 'rsiStatus'))), 'vor dem ersten Abgleich keine Farben');

        RsiOrg::sync($this->orgId);
        $by = array_column(Community::listMembers($this->orgId, $viewer), 'rsiStatus', 'name');
        $this->assertEquals(['Cem der Große' => 'unknown', 'eXpG_McDance (Micha)' => 'main', 'Pilot_1' => 'main', 'Ben' => 'affiliate'], $by);
    }
}
