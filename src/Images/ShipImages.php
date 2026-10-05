<?php

declare(strict_types=1);

namespace Hangar\Images;

use Hangar\Config;
use Hangar\Db;
use Hangar\Http\Client;
use Hangar\Http\NetworkError;
use Hangar\Text;
use Hangar\Time;

/**
 * Schiffsbilder aus der FleetYards-API, lazy auf dem Server gespeichert: Beim ersten Aufruf eines
 * Schiffs wird geprüft, ob das Bild schon vorhanden ist. Ja: das gespeicherte Bild wird genutzt.
 * Nein: es wird heruntergeladen, geprüft, gespeichert und angezeigt.
 */
final class ShipImages
{
    private const API = 'https://api.fleetyards.net/v1';
    /** Nur von diesen Hosts wird geladen (API und deren Bild-Speicher), nur per https. */
    public const ALLOWED_HOSTS = [
        'api.fleetyards.net', 'cdn.fltyrd.net', 'cdn.fleetyards.net', 'storage.fltyrd.net',
        'fltyrd-live-storage.fsn1.your-objectstorage.com',
    ];
    public const MAX_BYTES = 5 * 1024 * 1024;
    /** Nach einem Fehlschlag wird frühestens nach dieser Zeit erneut versucht. */
    public const RETRY_SECONDS = 86400;
    private const EXT = [IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png', IMAGETYPE_WEBP => 'webp'];
    private const MIME = ['jpg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp'];

    public static function validSlug(string $slug): bool
    {
        return (bool) preg_match('/^[a-z0-9][a-z0-9\-]{0,119}$/', $slug);
    }

    private static function dir(): string
    {
        return Config::imageDir() . '/ships';
    }

    /** Pfad der gespeicherten Datei, falls vorhanden. @return array{path:string,mime:string}|null */
    public static function existing(string $slug): ?array
    {
        if (!self::validSlug($slug)) {
            return null;
        }
        foreach (self::MIME as $ext => $mime) {
            $path = self::dir() . "/$slug.$ext";
            if (is_file($path)) {
                return ['path' => $path, 'mime' => $mime];
            }
        }
        return null;
    }

    /**
     * Liefert das Bild für ein Schiff des Katalogs, lädt es bei Bedarf herunter.
     * @return array{path:string,mime:string}|null null = kein Bild verfügbar (Platzhalter zeigen)
     */
    public static function ensure(string $slug): ?array
    {
        if (!self::validSlug($slug)) {
            return null;
        }
        // 1. Schon gespeichert?
        if (($have = self::existing($slug)) !== null) {
            return $have;
        }
        $row = Db::one("SELECT id, name, image_slug, image_checked_at FROM catalog_items WHERE kind = 'SHIP' AND slug = ?", [$slug]);
        if ($row === null) {
            return null;
        }
        // 2. Kürzlich schon vergeblich versucht?
        $checked = Time::parse($row['image_checked_at']);
        if ($checked !== null && Time::now()->getTimestamp() - $checked->getTimestamp() < self::RETRY_SECONDS) {
            return null;
        }

        $dir = self::dir();
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            return null;
        }
        // Parallele Aufrufe desselben Bildes: nur einer lädt, die anderen warten und nutzen das Ergebnis.
        $lock = fopen($dir . "/.lock-$slug", 'c');
        if ($lock === false) {
            return null;
        }
        try {
            flock($lock, LOCK_EX);
            if (($have = self::existing($slug)) !== null) {
                return $have;
            }
            $saved = null;
            try {
                $url = self::imageUrl($row);
                $img = $url !== null ? self::download($url) : null;
                if ($img !== null) {
                    $saved = self::store($slug, $img['body'], $img['ext']);
                }
            } catch (NetworkError | \RuntimeException) {
                $saved = null;
            }
            if ($saved === null) {
                Db::run('UPDATE catalog_items SET image_checked_at = ? WHERE id = ?', [Time::nowDb(), $row['id']]);
            }
            return $saved;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
            @unlink($dir . "/.lock-$slug");
        }
    }

    /** Bild-URL aus dem FleetYards-Modell; ermittelt bei Bedarf den FleetYards-Slug und merkt ihn sich. @param array<string,mixed> $row */
    private static function imageUrl(array $row): ?string
    {
        $fy = (string) ($row['image_slug'] ?? '');
        $model = $fy !== '' ? self::model($fy) : null;
        if ($model === null) {
            $model = self::findModel((string) $row['name']);
            if ($model !== null && isset($model['slug'])) {
                Db::run('UPDATE catalog_items SET image_slug = ? WHERE id = ?', [(string) $model['slug'], $row['id']]);
            }
        }
        $img = $model['media']['storeImage'] ?? null;
        if (!is_array($img)) {
            return null;
        }
        foreach (['mediumUrl', 'url', 'largeUrl', 'smallUrl'] as $k) {
            if (isset($img[$k]) && is_string($img[$k]) && $img[$k] !== '') {
                return $img[$k];
            }
        }
        return null;
    }

    /** @return array<string,mixed>|null */
    private static function model(string $fleetyardsSlug): ?array
    {
        $res = Client::get(self::API . '/models/' . rawurlencode($fleetyardsSlug), ['Accept' => 'application/json'], 15);
        if ($res['status'] !== 200) {
            return null;
        }
        $m = json_decode($res['body'], true);
        return is_array($m) ? $m : null;
    }

    /** Sucht das Modell über den Namen: erst als Slug (FleetYards leitet um), dann über die Suche. @return array<string,mixed>|null */
    private static function findModel(string $name): ?array
    {
        $key = Text::normalizeName($name);
        $direct = self::model(Text::slugify($name, 120, 'x'));
        if ($direct !== null && self::sameName($direct, $key)) {
            return $direct;
        }
        $res = Client::get(self::API . '/models?perPage=30&q=' . rawurlencode($name), ['Accept' => 'application/json'], 15);
        $body = $res['status'] === 200 ? json_decode($res['body'], true) : null;
        foreach (is_array($body) && is_array($body['items'] ?? null) ? $body['items'] : [] as $m) {
            if (is_array($m) && self::sameName($m, $key) && isset($m['slug'])) {
                return self::model((string) $m['slug']) ?? $m;
            }
        }
        return null;
    }

    /** @param array<string,mixed> $model */
    private static function sameName(array $model, string $key): bool
    {
        foreach (['name', 'rsiName'] as $f) {
            if (is_string($model[$f] ?? null) && Text::normalizeName($model[$f]) === $key) {
                return true;
            }
        }
        return false;
    }

    public static function hostAllowed(string $url): bool
    {
        $p = parse_url($url);
        return is_array($p) && ($p['scheme'] ?? '') === 'https' && in_array(strtolower($p['host'] ?? ''), self::ALLOWED_HOSTS, true);
    }

    /**
     * Lädt ein Bild. Weiterleitungen werden einzeln verfolgt, jeder Schritt muss auf einen erlaubten
     * Host zeigen. @return array{body:string,ext:string}|null
     */
    private static function download(string $url): ?array
    {
        for ($i = 0; $i < 5; $i++) {
            if (!self::hostAllowed($url)) {
                return null;
            }
            $res = Client::get($url, ['Accept' => 'image/*'], 15, false, self::MAX_BYTES);
            if ($res['status'] >= 300 && $res['status'] < 400 && !empty($res['headers']['location'])) {
                $url = self::absolute($url, $res['headers']['location']);
                continue;
            }
            if ($res['status'] !== 200 || $res['body'] === '' || strlen($res['body']) > self::MAX_BYTES) {
                return null;
            }
            $info = @getimagesizefromstring($res['body']);
            if ($info === false || !isset(self::EXT[$info[2]])) {
                return null;
            }
            return ['body' => $res['body'], 'ext' => self::EXT[$info[2]]];
        }
        return null;
    }

    private static function absolute(string $base, string $location): string
    {
        if (preg_match('#^https?://#i', $location)) {
            return $location;
        }
        $p = parse_url($base);
        return ($p['scheme'] ?? 'https') . '://' . ($p['host'] ?? '') . '/' . ltrim($location, '/');
    }

    /** Schreibt atomar (Temp-Datei + rename). @return array{path:string,mime:string}|null */
    private static function store(string $slug, string $body, string $ext): ?array
    {
        $path = self::dir() . "/$slug.$ext";
        $tmp = $path . '.' . bin2hex(random_bytes(4)) . '.tmp';
        if (file_put_contents($tmp, $body) === false) {
            return null;
        }
        if (!@rename($tmp, $path)) {
            @unlink($tmp);
            return is_file($path) ? ['path' => $path, 'mime' => self::MIME[$ext]] : null;
        }
        return ['path' => $path, 'mime' => self::MIME[$ext]];
    }

    /** Platzhalter, wenn kein Bild verfügbar ist. */
    public static function placeholderSvg(): string
    {
        return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 400 300"><rect width="400" height="300" fill="#27272a"/>'
            . '<path d="M200 105l70 90h-45l-25-30-25 30h-45z" fill="#3f3f46"/></svg>';
    }
}
