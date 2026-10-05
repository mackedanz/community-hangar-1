<?php
/** @var list<array<string,mixed>> $entries */
/** @var array<string,list<array<string,mixed>>> $groups */
/** @var ?\DateTimeImmutable $lastSync */
/** @var string $term */
/** @var list<array<string,mixed>> $results */
/** @var string $csrf */
use Hangar\Constants;
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
      <a href="<?= e(Constants::RSI_PLEDGES_URL) ?>" target="_blank" rel="noopener noreferrer" class="rounded bg-indigo-600 px-3 py-1.5 text-sm font-medium hover:bg-indigo-500">Jetzt synchronisieren</a>
    </div>
  </div>

  <section class="space-y-3 rounded border border-zinc-800 p-4">
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
        <ul class="divide-y divide-zinc-800 rounded border border-zinc-800">
          <?php foreach ($list as $entry): $c = $entry['catalogItem']; $img = $c['imageSrc'] ?? $entry['info']['image_url'] ?? null; ?>
            <li class="flex items-center gap-4 p-3">
              <div class="h-12 w-16 shrink-0 overflow-hidden rounded bg-zinc-900">
                <?php if ($img): ?><img src="<?= e($img) ?>" alt="" loading="lazy" class="h-full w-full object-cover"><?php endif; ?>
              </div>
              <div class="min-w-0 flex-1">
                <?php if ($c): ?>
                  <a href="/catalog/<?= e(strtolower($entry['kind'])) ?>/<?= e(rawurlencode($c['slug'])) ?>" class="font-medium hover:underline"><?= e(Hangar::entryName($entry)) ?></a>
                <?php else: ?>
                  <span class="font-medium"><?= e(Hangar::entryName($entry)) ?></span>
                <?php endif; ?>
                <?php if ($entry['info']): $i = $entry['info']; ?>
                  <div class="text-xs text-zinc-400">
                    <?php if ($i['type_label']): ?><span><?= e($i['type_label']) ?> · </span><?php endif; ?>
                    <span class="line-clamp-2" title="<?= e($i['description'] ?? '') ?>"><?= e($i['description'] ?? 'Keine Beschreibung') ?></span>
                    <?php if ($i['web_url']): ?><a href="<?= e($i['web_url']) ?>" target="_blank" rel="noopener noreferrer" class="text-indigo-400 hover:underline">Wiki</a><?php endif; ?>
                  </div>
                <?php else: ?>
                  <div class="text-xs text-zinc-400"><?= e($c['manufacturer'] ?? '–') ?></div>
                <?php endif; ?>
              </div>
              <div class="shrink-0 text-sm text-zinc-300"><?= (int) $entry['quantity'] ?>×<?php if ($entry['lti']): ?><span class="ml-2 text-zinc-400">LTI</span><?php endif; ?></div>
              <span class="shrink-0 rounded bg-zinc-800 px-1.5 py-0.5 text-xs text-zinc-400" title="<?= $entry['source'] === 'MANUAL' ? 'Manuell hinzugefügt, bleibt bei jedem Sync erhalten' : 'Von RSI übernommen, kehrt beim nächsten Sync zurück' ?>"><?= $entry['source'] === 'MANUAL' ? 'manuell' : 'RSI' ?></span>
              <form method="post" action="/hangar/remove" class="shrink-0">
                <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
                <input type="hidden" name="itemId" value="<?= e($entry['id']) ?>">
                <button class="text-sm text-red-400 hover:underline" title="<?= $entry['source'] === 'MANUAL' ? 'Aus dem Hangar entfernen' : 'Entfernen (kehrt beim nächsten RSI-Sync zurück)' ?>">Entfernen</button>
              </form>
            </li>
          <?php endforeach; ?>
        </ul>
      </div>
    </section>
  <?php endforeach; ?>
</div>
