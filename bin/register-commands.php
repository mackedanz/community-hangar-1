<?php

declare(strict_types=1);

// Registriert die Slash-Befehle des Onboarding-Bots bei Discord (einmalig und nach Änderungen).
// Braucht AUTH_DISCORD_ID und DISCORD_BOT_TOKEN. Nutzung: php bin/register-commands.php
require dirname(__DIR__) . '/vendor/autoload.php';

date_default_timezone_set('UTC');

if (Hangar\Env::get('DISCORD_BOT_TOKEN') === null || Hangar\Env::get('AUTH_DISCORD_ID') === null) {
    fwrite(STDERR, "DISCORD_BOT_TOKEN und AUTH_DISCORD_ID müssen gesetzt sein.\n");
    exit(1);
}
$status = Hangar\DiscordBot::registerCommands([
    [
        'name' => 'einrichten',
        'description' => 'Community-Hangar für diesen Server einrichten: Rollen wählen und Mitglieder übernehmen',
        'type' => 1,
        'default_member_permissions' => '32', // "Server verwalten"; die App prüft die Rechte zusätzlich selbst
        'dm_permission' => false,
    ],
    [
        'name' => 'abgleichen',
        'description' => 'Mitglieder mit den Discord-Rollen abgleichen: wer darf sich im Community-Hangar anmelden',
        'type' => 1,
        'dm_permission' => false, // für alle sichtbar; die App erlaubt es nur Mitgliedern der Orga und Server-Admins
    ],
]);
echo $status >= 200 && $status < 300 ? "Befehle registriert.\n" : "Discord antwortet mit Status $status.\n";
exit($status >= 200 && $status < 300 ? 0 : 1);
