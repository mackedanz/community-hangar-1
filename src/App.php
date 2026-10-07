<?php

declare(strict_types=1);

namespace Hangar;

use Hangar\Controllers\AdminController;
use Hangar\Controllers\ApiController;
use Hangar\Controllers\CatalogController;
use Hangar\Controllers\DevController;
use Hangar\Controllers\DiscordController;
use Hangar\Controllers\EventController;
use Hangar\Controllers\HangarController;
use Hangar\Controllers\HomeController;
use Hangar\Controllers\ImageController;
use Hangar\Controllers\OrgController;
use Hangar\Controllers\SettingsController;
use Hangar\Controllers\SyncController;
use Hangar\Http\Flash;
use Hangar\Http\Request;
use Hangar\Http\Response;
use Hangar\Http\Router;
use Hangar\Http\View;

/** Verdrahtet Routen und liefert pro Anfrage genau eine Antwort. */
final class App
{
    public static function routes(): Router
    {
        $r = new Router();

        // Start, Anmeldung
        $r->get('/', [HomeController::class, 'home']);
        $r->get('/login', [HomeController::class, 'login']);
        $r->post('/logout', [HomeController::class, 'logout']);
        $r->post('/recheck', [HomeController::class, 'recheck']);
        $r->get('/auth/discord', fn () => Auth::startLogin());
        $r->get('/api/auth/callback/discord', fn (Request $req) => Auth::finishLogin($req));
        $r->get('/healthz', fn () => Response::text("ok
"));
        $r->get('/datenschutz', [SettingsController::class, 'privacy']);

        // Katalog und Bilder
        $r->get('/catalog', [CatalogController::class, 'index']);
        $r->get('/catalog/{kind}/{slug}', [CatalogController::class, 'show']);
        $r->get('/img/ship/{slug}', [ImageController::class, 'ship']);
        $r->get('/img/armor/{slug}', [ImageController::class, 'armor']);
        $r->get('/img/info/{key}', [ImageController::class, 'info']);

        // Mein Hangar
        $r->get('/hangar', [HangarController::class, 'index']);
        $r->get('/hangar/export', [HangarController::class, 'export']);
        $r->post('/hangar/add', [HangarController::class, 'add']);
        $r->post('/hangar/remove', [HangarController::class, 'remove']);
        $r->post('/hangar/remove-all', [HangarController::class, 'removeAll']);

        // Profile, Einstellungen, API-Tokens
        $r->get('/members/{id}', [SettingsController::class, 'profile']);
        $r->get('/settings', [SettingsController::class, 'index']);
        $r->post('/settings', [SettingsController::class, 'save']);
        $r->post('/settings/delete-account', [SettingsController::class, 'deleteAccount']);
        $r->get('/settings/api', [SettingsController::class, 'api']);
        $r->post('/settings/api', [SettingsController::class, 'createToken']);
        $r->post('/settings/api/revoke', [SettingsController::class, 'revokeToken']);

        // RSI-Sync und API (die API prüft Bearer-Token bzw. Sitzung + JSON statt CSRF-Token)
        $r->get('/sync', [SyncController::class, 'index']);
        $r->get('/sync/receive', [SyncController::class, 'receive']);
        $r->post('/api/v1/import/hangar', [ApiController::class, 'importHangar'], false);
        $r->get('/api/v1/me/items', [ApiController::class, 'myItems']);
        // Onboarding-Bot (Discord ruft diesen Endpunkt auf; Schutz durch Ed25519-Signatur statt CSRF)
        $r->post('/discord/interactions', [DiscordController::class, 'interactions'], false);

        // Orgas
        $r->post('/orgs/update', [OrgController::class, 'update']);
        $r->post('/orgs/delete', [OrgController::class, 'delete']);
        $r->post('/orgs/sync-allowlist', [OrgController::class, 'syncAllowlist']);
        $r->post('/orgs/rsi', [OrgController::class, 'saveRsi']);
        $r->get('/o/{slug}', [OrgController::class, 'home']);
        $r->get('/o/{slug}/members', [OrgController::class, 'members']);
        $r->get('/o/{slug}/hangar', [OrgController::class, 'hangar']);
        $r->get('/o/{slug}/stats', [OrgController::class, 'stats']);
        $r->get('/o/{slug}/settings', [OrgController::class, 'settings']);

        // Planung
        $r->get('/o/{slug}/events', [EventController::class, 'index']);
        $r->get('/o/{slug}/events/new', [EventController::class, 'newForm']);
        $r->post('/o/{slug}/events/save', [EventController::class, 'save']);
        $r->get('/o/{slug}/events/{id}', [EventController::class, 'show']);
        $r->get('/o/{slug}/events/{id}/edit', [EventController::class, 'editForm']);
        $r->post('/o/{slug}/events/{id}/rsvp', [EventController::class, 'rsvp']);
        $r->post('/o/{slug}/events/{id}/cancel', [EventController::class, 'cancel']);
        $r->post('/o/{slug}/events/{id}/delete', [EventController::class, 'delete']);

        // Betreiber
        $r->get('/admin', [AdminController::class, 'index']);
        $r->post('/admin/delete-org', [AdminController::class, 'deleteOrg']);
        $r->post('/admin/ban', [AdminController::class, 'ban']);
        $r->post('/admin/unban', [AdminController::class, 'unban']);

        if (DevController::enabled()) {
            $r->get('/dev/login', [DevController::class, 'form']);
            $r->post('/dev/login', [DevController::class, 'login'], false);
        }

        return $r;
    }

    private static function setsFlash(Response $res): bool
    {
        foreach ($res->cookies as [$name, $value]) {
            if ($name === Flash::COOKIE && $value !== '') {
                return true;
            }
        }
        return false;
    }

    public static function handle(Request $req): Response
    {
        Auth::reset();
        View::resetShared();
        try {
            $viewer = $req->path === '/healthz' ? null : Auth::viewer($req);
        } catch (\Throwable) {
            $viewer = null;
        }
        $flash = Flash::read($req);
        View::share([
            'viewer' => $viewer,
            'serverAdmin' => Auth::isServerAdmin($viewer),
            'csrf' => $viewer?->csrf ?? '',
            'flash' => $flash,
        ]);
        $res = self::routes()->dispatch($req);
        // Meldung wurde dieser Seite angezeigt: Cookie löschen (außer die Antwort setzt gerade eine neue)
        if ($flash !== null && !self::setsFlash($res)) {
            Flash::clear($res);
        }
        return $res
            ->withHeader('X-Content-Type-Options', 'nosniff')
            ->withHeader('Referrer-Policy', 'same-origin')
            ->withHeader('X-Frame-Options', 'DENY');
    }
}
