<?php

declare(strict_types=1);

namespace Hangar\Controllers;

use Hangar\Auth;
use Hangar\Community;
use Hangar\Constants;
use Hangar\FleetFilter;
use Hangar\Hangar;
use Hangar\HangarError;
use Hangar\Http\Flash;
use Hangar\Http\Request;
use Hangar\Http\Response;

final class HangarController extends Controller
{
    public static function index(Request $req): Response
    {
        $viewer = Auth::requireViewer($req);
        $term = trim((string) $req->query('add', ''));
        $q = mb_substr(trim((string) $req->query('q', '')), 0, 100);
        $entries = Hangar::listHangar($viewer->id);
        $filter = FleetFilter::parseFilter($req->query);
        $options = FleetFilter::options(array_filter(array_map(
            fn ($e) => $e['kind'] === 'SHIP' ? FleetFilter::parseSpecs($e['catalogItem']['data'] ?? null) : null,
            $entries,
        )));
        $specFilter = array_diff_key($filter, ['q' => 1]);
        $shown = array_values(array_filter($entries, fn ($e) => ($q === '' || Hangar::matchesText($e, $q))
            && ($specFilter === [] || FleetFilter::matches(FleetFilter::parseSpecs($e['catalogItem']['data'] ?? null), $specFilter))));
        return self::page('hangar', [
            'entries' => $entries,
            'q' => $q, 'filter' => $filter, 'options' => $options, 'filtering' => $q !== '' || $specFilter !== [],
            'groups' => Hangar::groupByKind($shown),
            'lastSync' => Community::getLastSync($viewer->id),
            'term' => $term,
            'results' => $term !== '' ? Hangar::searchCatalog($term) : [],
            'wide' => true,
        ], 'Mein Hangar');
    }

    public static function add(Request $req): Response
    {
        $viewer = Auth::requireViewer($req);
        $back = '/hangar' . (($t = trim((string) $req->input('add', ''))) !== '' ? '?add=' . rawurlencode($t) : '');
        if (($req->input('return') ?? '') !== '' && str_starts_with((string) $req->input('return'), '/catalog/')) {
            $back = (string) $req->input('return');
        }
        try {
            Hangar::addItem($viewer->id, (string) $req->input('catalogItemId', ''), $req->input('quantity', '1'), $req->input('lti') === 'on');
        } catch (HangarError $e) {
            return Flash::error($back, $e->getMessage());
        }
        return Flash::ok($back, 'Hinzugefügt.');
    }

    public static function remove(Request $req): Response
    {
        $viewer = Auth::requireViewer($req);
        Hangar::removeItem($viewer->id, (string) $req->input('itemId', ''));
        return Response::redirect('/hangar');
    }

    public static function removeAll(Request $req): Response
    {
        $viewer = Auth::requireViewer($req);
        $kind = (string) $req->input('kind', '');
        if (Constants::isKind($kind)) {
            Hangar::removeAllOfKind($viewer->id, $kind);
        }
        return Response::redirect('/hangar');
    }

    /** Eigenen Hangar als JSON oder CSV herunterladen: /hangar/export?format=csv&kind=SHIP */
    public static function export(Request $req): Response
    {
        $viewer = Auth::viewer($req);
        if ($viewer === null) {
            return Response::text('Nicht angemeldet', 401);
        }
        $format = $req->query('format') === 'csv' ? 'csv' : 'json';
        $kindParam = (string) $req->query('kind', '');
        $kind = Constants::isKind($kindParam) ? $kindParam : null;
        $rows = Hangar::exportRows(Hangar::listHangar($viewer->id), $kind);
        $name = 'hangar' . ($kind !== null ? '-' . strtolower($kind) : '');

        $res = $format === 'csv'
            ? Response::text(Hangar::toCsv($rows), 200, 'text/csv; charset=utf-8')
            : Response::text((string) json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 200, 'application/json; charset=utf-8');
        return $res->withHeader('Content-Disposition', "attachment; filename=\"$name.$format\"")->withHeader('Cache-Control', 'no-store');
    }
}
