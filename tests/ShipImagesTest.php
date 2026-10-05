<?php

declare(strict_types=1);

namespace Hangar\Tests;

use Hangar\Config;
use Hangar\Db;
use Hangar\Env;
use Hangar\Http\Client;
use Hangar\Http\NetworkError;
use Hangar\Images\ShipImages;

final class ShipImagesTest extends DbTestCase
{
    private string $dir;
    /** @var list<string> */
    private array $urls = [];
    private string $png;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir() . '/hangar-img-' . bin2hex(random_bytes(4));
        mkdir($this->dir, 0775, true);
        Env::set('IMAGE_DIR', $this->dir);
        // kleines gültiges PNG (1x1)
        $this->png = (string) base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');
        Db::insert('catalog_items', ['id' => 'c1', 'kind' => 'SHIP', 'slug' => 'retaliator', 'name' => 'Retaliator', 'match_key' => 'retaliator', 'source' => 'RSI_MATRIX', 'image_slug' => 'aegs-retaliator', 'data' => '{}']);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/ships/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->dir . '/ships');
        @rmdir($this->dir);
        parent::tearDown();
    }

    /** Fake für FleetYards-API und Bild-Speicher mit einer Weiterleitung wie im echten Betrieb. */
    private function installFleetYards(?string $imageBody = null, int $imageStatus = 200, string $storageHost = 'storage.fltyrd.net'): void
    {
        $imageBody ??= $this->png;
        Client::fake(function (string $m, string $url) use ($imageBody, $imageStatus, $storageHost) {
            $this->urls[] = $url;
            if (str_starts_with($url, 'https://api.fleetyards.net/v1/models/aegs-retaliator')) {
                return ['status' => 200, 'headers' => [], 'body' => json_encode([
                    'slug' => 'aegs-retaliator', 'name' => 'Retaliator',
                    'media' => ['storeImage' => ['mediumUrl' => 'https://api.fleetyards.net/files/representations/redirect/abc']],
                ])];
            }
            if (str_starts_with($url, 'https://api.fleetyards.net/files/')) {
                return ['status' => 302, 'headers' => ['location' => "https://$storageHost/xyz?origin="], 'body' => ''];
            }
            if (str_contains($url, '/xyz')) {
                return ['status' => $imageStatus, 'headers' => ['content-type' => 'image/png'], 'body' => $imageBody];
            }
            return ['status' => 404, 'headers' => [], 'body' => ''];
        });
    }

    public function testDownloadsOnFirstCallAndStoresTheFile(): void
    {
        $this->installFleetYards();
        $img = ShipImages::ensure('retaliator');
        $this->assertNotNull($img);
        $this->assertSame('image/png', $img['mime']);
        $this->assertFileExists($this->dir . '/ships/retaliator.png');
        $this->assertSame($this->png, file_get_contents($img['path']));
        $this->assertNull(Db::val('SELECT image_checked_at FROM catalog_items WHERE id = ?', ['c1']));
    }

    public function testUsesStoredFileWithoutAnyHttpCall(): void
    {
        $this->installFleetYards();
        ShipImages::ensure('retaliator');
        $this->urls = [];
        Client::fake(function (): never {
            throw new \LogicException('Es darf kein HTTP-Aufruf stattfinden');
        });
        $this->assertNotNull(ShipImages::ensure('retaliator'));
        $this->assertSame([], $this->urls);
    }

    public function testRejectsDisallowedHostsAndStoresNothing(): void
    {
        $this->installFleetYards(null, 200, 'evil.example');
        $this->assertNull(ShipImages::ensure('retaliator'));
        $this->assertSame([], glob($this->dir . '/ships/*.png'));
        $this->assertNotNull(Db::val('SELECT image_checked_at FROM catalog_items WHERE id = ?', ['c1']));
        foreach ($this->urls as $u) {
            $this->assertStringNotContainsString('evil.example', $u);
        }
    }

    public function testRejectsNonImagesAndHttpErrors(): void
    {
        $this->installFleetYards('<html>kein Bild</html>');
        $this->assertNull(ShipImages::ensure('retaliator'));
        Db::run('UPDATE catalog_items SET image_checked_at = NULL');
        $this->installFleetYards($this->png, 404);
        $this->assertNull(ShipImages::ensure('retaliator'));
        $this->assertSame([], glob($this->dir . '/ships/*') ?: []);
    }

    public function testDoesNotRetryWithin24HoursAfterFailure(): void
    {
        Client::fake(function () {
            throw new NetworkError('offline');
        });
        $this->assertNull(ShipImages::ensure('retaliator'));
        $this->assertNotNull(Db::val('SELECT image_checked_at FROM catalog_items WHERE id = ?', ['c1']));

        $this->urls = [];
        $this->installFleetYards();
        $this->assertNull(ShipImages::ensure('retaliator'));
        $this->assertSame([], $this->urls);

        // Nach mehr als 24 Stunden wird erneut versucht
        Db::run('UPDATE catalog_items SET image_checked_at = ? WHERE id = ?', [gmdate('Y-m-d H:i:s', time() - ShipImages::RETRY_SECONDS - 60), 'c1']);
        $this->assertNotNull(ShipImages::ensure('retaliator'));
    }

    public function testInvalidSlugsNeverTouchTheFilesystemOrNetwork(): void
    {
        $this->installFleetYards();
        foreach (['../etc/passwd', 'a/b', '..', '', 'UPPER', str_repeat('a', 130), "x\0y"] as $bad) {
            $this->assertFalse(ShipImages::validSlug($bad), $bad);
            $this->assertNull(ShipImages::ensure($bad));
        }
        $this->assertSame([], $this->urls);
    }

    public function testUnknownCatalogSlugGivesNoImage(): void
    {
        $this->installFleetYards();
        $this->assertNull(ShipImages::ensure('gibt-es-nicht'));
        $this->assertSame([], $this->urls);
    }

    public function testFindsFleetYardsSlugByNameWhenNotKnown(): void
    {
        Db::run('UPDATE catalog_items SET image_slug = NULL');
        Client::fake(function (string $m, string $url) {
            $this->urls[] = $url;
            if (str_contains($url, '/models/retaliator')) {
                return ['status' => 200, 'headers' => [], 'body' => json_encode([
                    'slug' => 'aegs-retaliator', 'name' => 'Retaliator',
                    'media' => ['storeImage' => ['url' => 'https://storage.fltyrd.net/xyz']],
                ])];
            }
            if (str_contains($url, '/xyz')) {
                return ['status' => 200, 'headers' => [], 'body' => $this->png];
            }
            return ['status' => 404, 'headers' => [], 'body' => ''];
        });
        $this->assertNotNull(ShipImages::ensure('retaliator'));
        $this->assertSame('aegs-retaliator', Db::val('SELECT image_slug FROM catalog_items WHERE id = ?', ['c1']));
    }

    public function testHostAllowlist(): void
    {
        $this->assertTrue(ShipImages::hostAllowed('https://storage.fltyrd.net/x'));
        $this->assertFalse(ShipImages::hostAllowed('http://storage.fltyrd.net/x'));
        $this->assertFalse(ShipImages::hostAllowed('https://storage.fltyrd.net.evil.test/x'));
        $this->assertFalse(ShipImages::hostAllowed('https://evil.test/storage.fltyrd.net'));
        $this->assertSame($this->dir, Config::imageDir());
    }
}
