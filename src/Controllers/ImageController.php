<?php

declare(strict_types=1);

namespace Hangar\Controllers;

use Hangar\Http\HttpException;
use Hangar\Http\Request;
use Hangar\Http\Response;
use Hangar\Images\ItemImages;
use Hangar\Images\ShipImages;

final class ImageController extends Controller
{
    /** GET /img/ship/{slug}: gespeichertes Bild, sonst Download beim ersten Aufruf, sonst Platzhalter. */
    public static function ship(Request $req, array $p): Response
    {
        $slug = (string) ($p['slug'] ?? '');
        if (!ShipImages::validSlug($slug)) {
            throw HttpException::notFound();
        }
        return self::serve($req, ShipImages::ensure($slug));
    }

    /** GET /img/armor/{slug}: Rüstungen aus dem Katalog (Quelle: Star Citizen Wiki). */
    public static function armor(Request $req, array $p): Response
    {
        return self::item($req, 'armor', (string) ($p['slug'] ?? ''));
    }

    /** GET /img/info/{key}: Ausrüstung und Loot aus dem Hangar (Quelle: Wiki, über item_info). */
    public static function info(Request $req, array $p): Response
    {
        return self::item($req, 'info', (string) ($p['key'] ?? ''));
    }

    private static function item(Request $req, string $scope, string $key): Response
    {
        if (!ItemImages::validKey($key)) {
            throw HttpException::notFound();
        }
        return self::serve($req, ItemImages::ensure($scope, $key));
    }

    /** @param array{path:string,mime:string}|null $img null = Platzhalter */
    private static function serve(Request $req, ?array $img): Response
    {
        if ($img === null) {
            // Kurz cachen, damit ein später erfolgreicher Download bald sichtbar wird
            return Response::text(ShipImages::placeholderSvg(), 200, 'image/svg+xml')
                ->withHeader('Cache-Control', 'public, max-age=300');
        }
        $mtime = (int) filemtime($img['path']);
        $etag = '"' . dechex($mtime) . '-' . dechex((int) filesize($img['path'])) . '"';
        $headers = [
            'Content-Type' => $img['mime'],
            'Cache-Control' => 'public, max-age=604800',
            'ETag' => $etag,
            'Last-Modified' => gmdate('D, d M Y H:i:s', $mtime) . ' GMT',
        ];
        if ($req->header('if-none-match') === $etag) {
            return new Response(304, '', $headers);
        }
        return new Response(200, (string) file_get_contents($img['path']), $headers);
    }
}
