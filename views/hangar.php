<?php
/** @var list<array<string,mixed>> $entries */
/** @var array<string,list<array<string,mixed>>> $groups */
/** @var ?\DateTimeImmutable $lastSync */
/** @var string $term */
/** @var list<array<string,mixed>> $results */
/** @var string $csrf */
use Hangar\Constants;
use Hangar\FleetFilter;
use Hangar\Hangar;
use Hangar\Http\View;
?>
<div class="space-y-8">
  <div class="flex flex-wrap items-center justify-between gap-3">
    <div>
      <h1 class="text-2xl font-bold">Mein Hangar</h1>
      <p class="text-sm text-zinc-400">Zuletzt mit RSI synchronisiert: <?= $lastSync ? e(dt($lastSync)) : 'noch nie' ?></p>
    </div>
    <div class="flex flex-wrap items-center gap-3">
      <?php if ($entries): ?>
        <span class="text-sm text-zinc-400">Gesamten Hangar exportieren:
          <a href="/hangar/export?format=json" class="text-indigo-400 hover:underline">JSON</a> ·
          <a href="/hangar/export?format=csv" class="text-indigo-400 hover:underline">CSV</a>
        </span>
      <?php endif; ?>
      <a href="<?= e(Constants::RSI_PLEDGES_URL) ?>" target="_blank" rel="noopener noreferrer" data-rsi-sync class="rounded bg-indigo-600 px-3 py-1.5 text-sm font-medium hover:bg-indigo-500">Jetzt synchronisieren</a>
    </div>
  </div>

  <section class="max-w-5xl space-y-3 rounded border border-zinc-800 p-4">
    <h2 class="font-semibold">Manuell hinzufügen</h2>
    <p class="text-sm text-zinc-400">
      Für Schiffe, die nicht über RSI kommen (z. B. im Spiel gekauft oder geliehen). Manuelle
      Einträge bleiben bei jedem RSI-Sync erhalten.
    </p>
    <form class="flex gap-2" method="get" action="/hangar">
      <input name="add" value="<?= e($term) ?>" placeholder="Schiff oder Rüstung suchen …" class="w-full max-w-sm rounded border border-zinc-700 bg-zinc-900 px-3 py-1.5 text-sm">
      <button class="rounded bg-zinc-800 px-3 py-1.5 text-sm hover:bg-zinc-700">Suchen</button>
    </form>
    <?php if ($term !== '' && !$results): ?>
      <p class="text-sm text-zinc-400">Nichts gefunden für „<?= e($term) ?>“.</p>
    <?php endif; ?>
    <?php if ($results): ?>
      <ul class="divide-y divide-zinc-800 rounded border border-zinc-800">
        <?php foreach ($results as $r): ?>
          <li class="flex flex-wrap items-center justify-between gap-3 p-3">
            <div class="min-w-0">
              <div class="font-medium"><?= e($r['name']) ?></div>
              <div class="text-xs text-zinc-400"><?= e($r['manufacturer'] ?? '–') ?> · <?= $r['kind'] === 'SHIP' ? 'Schiff' : 'Rüstung' ?></div>
            </div>
            <?= View::partial('partials/add_item_form', ['catalogItemId' => $r['id'], 'csrf' => $csrf, 'term' => $term]) ?>
          </li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>
  </section>

  <?php if ($entries): ?>
    <form method="get" action="/hangar" class="flex flex-wrap items-center gap-2">
      <input type="search" name="q" value="<?= e($q) ?>" maxlength="100" placeholder="Im Hangar suchen: Name, Hersteller, Rolle, LTI …" class="w-full max-w-sm rounded border border-zinc-700 bg-zinc-900 px-3 py-1.5 text-sm">
      <button class="rounded bg-zinc-800 px-3 py-1.5 text-sm hover:bg-zinc-700">Suchen</button>
      <?php if ($q !== ''): ?><a href="/hangar" class="text-sm text-zinc-400 hover:underline">Zurücksetzen</a><?php endif; ?>
    </form>
    <?php if ($q !== '' && !$groups): ?>
      <p class="text-zinc-400">Nichts im Hangar gefunden für „<?= e($q) ?>“.</p>
    <?php endif; ?>
  <?php endif; ?>

  <?php if (!$entries): ?>
    <p class="text-zinc-400">
      Dein Hangar ist noch leer. Du kannst Schiffe oben manuell hinzufügen oder ihn von deiner
      RSI-Pledge-Seite übertragen. <a href="/sync" class="text-indigo-400 hover:underline">So richtest du den Sync ein</a>.
    </p>
  <?php endif; ?>

  <?php foreach (Constants::ITEM_KINDS as $kind): if (!isset($groups[$kind])) { continue; } $list = $groups[$kind]; $label = Constants::KIND_LABELS[$kind]; ?>
    <section class="space-y-3">
      <div class="flex items-center justify-between gap-3">
        <button type="button" data-collapse="#sec-<?= e($kind) ?>" aria-expanded="true" class="flex items-center gap-2 text-left">
          <span aria-hidden="true" data-arrow class="w-4 text-sm text-zinc-400">▾</span>
          <h2 class="text-lg font-semibold"><?= e($label) ?> <span class="text-sm font-normal text-zinc-400">(<?= array_sum(array_column($list, 'quantity')) ?>)</span></h2>
        </button>
        <div class="flex items-center gap-4">
          <span class="text-sm text-zinc-400">Export:
            <a href="/hangar/export?format=json&amp;kind=<?= e($kind) ?>" class="text-indigo-400 hover:underline">JSON</a> ·
            <a href="/hangar/export?format=csv&amp;kind=<?= e($kind) ?>" class="text-indigo-400 hover:underline">CSV</a>
          </span>
          <form method="post" action="/hangar/remove-all" data-confirm="Wirklich alle <?= count($list) ?> <?= e($label) ?> aus deinem Hangar entfernen?&#10;&#10;Von RSI übernommene Einträge kommen beim nächsten RSI-Sync zurück, manuell hinzugefügte nicht.">
            <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
            <input type="hidden" name="kind" value="<?= e($kind) ?>">
            <button class="text-sm text-red-400 hover:underline">Alle entfernen</button>
          </form>
        </div>
      </div>
      <div id="sec-<?= e($kind) ?>">
        <ul class="grid grid-cols-2 gap-3 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-5 xl:grid-cols-6 2xl:grid-cols-7 3xl:grid-cols-8">
          <?php foreach ($list as $entry):
            $c = $entry['catalogItem'];
            $i = $entry['info'];
            $manual = $entry['source'] === 'MANUAL';
            ob_start(); ?>
            <?php if ($manual): ?><span class="rounded bg-zinc-900/80 px-1.5 py-0.5 text-[10px] text-zinc-300" title="Manuell hinzugefügt, bleibt bei jedem Sync erhalten">manuell</span><?php endif; ?>
            <form method="post" action="/hangar/remove">
              <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
              <input type="hidden" name="itemId" value="<?= e($entry['id']) ?>">
              <button class="rounded bg-zinc-900/80 px-1.5 py-0.5 text-[10px] text-red-400 hover:bg-red-950" title="<?= $manual ? 'Aus dem Hangar entfernen' : 'Entfernen (kehrt beim nächsten RSI-Sync zurück)' ?>">Entfernen</button>
            </form>
            <?php $actions = (string) ob_get_clean(); ?>
            <?= View::partial('partials/item_tile', [
              'name' => Hangar::entryName($entry),
              'href' => $c ? '/catalog/' . strtolower($entry['kind']) . '/' . rawurlencode($c['slug']) : null,
              'subtitle' => $i && $i['type_label'] ? $i['type_label'] . ($c['manufacturer'] ?? null ? ' · ' . $c['manufacturer'] : '') : ($c['manufacturer'] ?? null),
              'count' => (int) $entry['quantity'],
              'image' => $c['imageSrc'] ?? (!empty($i['image_url']) ? '/img/info/' . rawurlencode($i['match_key']) : null),
              'tooltip' => $i['description'] ?? Hangar::entryName($entry),
              'lti' => (bool) $entry['lti'],
              'notReady' => $entry['kind'] === 'SHIP' && $c ? FleetFilter::notReadyLabel(FleetFilter::parseSpecs($c['data'] ?? null)['status']) : null,
              'actions' => $actions,
            ]) ?>
          <?php endforeach; ?>
        </ul>
      </div>
    </section>
  <?php endforeach; ?>
</div>
<script src="/js/sync-receive.js" defer></script>
