<?php
/** @var ?\DateTimeImmutable $lastSync */
/** @var string $bookmarklet */
use Hangar\Constants;

$link = 'text-indigo-400 hover:underline';
$kbd = 'rounded bg-zinc-800 px-1.5 py-0.5';
?>
<div class="max-w-2xl space-y-8">
  <div>
    <h1 class="text-2xl font-bold">Hangar mit RSI synchronisieren</h1>
    <p class="mt-1 text-sm text-zinc-400">
      Ein Lesezeichen liest deine Pledges aus, während du auf robertsspaceindustries.com
      angemeldet bist, und übergibt sie hierher. Jeder Sync ersetzt die von RSI übernommenen
      Einträge; manuell hinzugefügte Schiffe bleiben erhalten. Du brauchst dafür keine
      Erweiterung.
    </p>
    <p class="mt-2 text-sm text-zinc-300">Zuletzt synchronisiert: <b><?= $lastSync ? e(dt($lastSync)) : 'noch nie' ?></b></p>
  </div>

  <section class="space-y-3">
    <h2 class="text-lg font-semibold">Einmalig: Lesezeichen anlegen</h2>
    <ol class="list-decimal space-y-3 pl-5 text-sm text-zinc-300">
      <li>Blende die Lesezeichenleiste ein, falls sie nicht sichtbar ist:
        <kbd class="<?= $kbd ?>">Strg</kbd> + <kbd class="<?= $kbd ?>">Umschalt</kbd> + <kbd class="<?= $kbd ?>">B</kbd>.</li>
      <li class="space-y-2">
        <p>Ziehe diesen Knopf mit gedrückter Maustaste in die Lesezeichenleiste und lass ihn dort los:</p>
        <a href="<?= e($bookmarklet) ?>" draggable="true" data-bookmarklet class="inline-block cursor-grab rounded border-2 border-dashed border-indigo-400 bg-indigo-600 px-4 py-2 font-medium text-white hover:bg-indigo-500">⇪ Hangar-Sync</a>
        <p class="text-zinc-400">
          In der Leiste erscheint jetzt ein Lesezeichen „⇪ Hangar-Sync“. Nicht hier anklicken,
          es funktioniert nur auf der RSI-Seite.
        </p>
      </li>
    </ol>
  </section>

  <section class="space-y-3">
    <h2 class="text-lg font-semibold">Synchronisieren</h2>
    <ol class="list-decimal space-y-2 pl-5 text-sm text-zinc-300">
      <li>Melde dich auf RSI an und öffne <a href="<?= e(Constants::RSI_PLEDGES_URL) ?>" target="_blank" rel="noopener noreferrer" class="<?= $link ?>">deine Pledge-Seite</a>.</li>
      <li>Klicke dort in der Lesezeichenleiste auf <b>„⇪ Hangar-Sync“</b>. Unten rechts siehst du, wie die Seiten gelesen werden, und es öffnet sich ein Fenster des Community-Hangars.</li>
      <li>Im Community-Hangar-Fenster erscheint eine Vorschau. Prüfe sie und klicke auf <b>„In meinen Hangar übernehmen“</b>.</li>
    </ol>
    <p class="text-xs text-zinc-400">
      Meldet der Browser ein blockiertes Pop-up, erlaube Pop-ups für robertsspaceindustries.com
      (Symbol rechts in der Adressleiste) und klicke das Lesezeichen erneut an.
    </p>
    <a href="<?= e(Constants::RSI_PLEDGES_URL) ?>" target="_blank" rel="noopener noreferrer" class="inline-block rounded bg-indigo-600 px-4 py-2 text-sm font-medium hover:bg-indigo-500">RSI-Pledge-Seite öffnen</a>
  </section>

  <p class="text-xs text-zinc-500">
    Das Lesezeichen läuft nur, wenn du es auf deiner RSI-Pledge-Seite anklickst. Es liest nur,
    was du dort selbst siehst, entfernt Gutscheincodes und übergibt die Liste an dieses
    Fenster; übernommen wird erst nach deiner Bestätigung. Deine RSI-Zugangsdaten bekommt es
    nie. Für eigene Skripte gibt es zusätzlich die <a href="/settings/api" class="<?= $link ?>">API mit persönlichen Tokens</a>.
  </p>
</div>
