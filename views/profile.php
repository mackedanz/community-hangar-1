<?php
/** @var array<string,mixed> $profile */
/** @var ?array<string,list<array<string,mixed>>> $groups */
use Hangar\Constants;
use Hangar\Hangar;

$u = $profile['user'];
?>
<div class="space-y-8">
  <div class="flex items-center gap-4">
    <?php if ($u['image']): ?><img src="<?= e($u['image']) ?>" alt="" class="h-16 w-16 rounded-full"><?php else: ?><div class="h-16 w-16 rounded-full bg-zinc-800"></div><?php endif; ?>
    <div>
      <h1 class="text-2xl font-bold"><?= e($u['name'] ?? 'Unbekannt') ?></h1>
      <?php if ($u['rsiUrl']): ?>
        <a href="<?= e($u['rsiUrl']) ?>" target="_blank" rel="noopener noreferrer" title="RSI-Profil von <?= e($u['rsiHandle']) ?> öffnen"
           class="mt-1 inline-block rounded border border-cyan-800/60 bg-teal-950/50 px-3 py-1 text-sm text-zinc-200 hover:border-cyan-500/80">RSI-Profil: <?= e($u['rsiHandle']) ?> ↗</a>
      <?php endif; ?>
    </div>
  </div>

  <section class="space-y-3">
    <h2 class="text-lg font-semibold">Errungenschaften</h2>
    <?php if ($profile['achievements'] === null): ?>
      <p class="text-sm text-zinc-400">Nicht sichtbar.</p>
    <?php elseif (!$profile['achievements']): ?>
      <p class="text-sm text-zinc-400">Noch keine.</p>
    <?php else: ?>
      <ul class="grid gap-2 sm:grid-cols-2">
        <?php foreach ($profile['achievements'] as $a): ?>
          <li class="rounded border border-zinc-800 p-3">
            <div class="font-medium">🏅 <?= e($a['title']) ?></div>
            <div class="text-xs text-zinc-400"><?= e($a['description']) ?></div>
          </li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>
  </section>

  <section class="space-y-4">
    <div>
      <h2 class="text-lg font-semibold">Hangar</h2>
      <?php if ($groups !== null): ?><p class="text-xs text-zinc-400">Zuletzt mit RSI synchronisiert: <?= $profile['lastSync'] ? e(dt($profile['lastSync'])) : 'noch nie' ?></p><?php endif; ?>
    </div>
    <?php if ($groups === null): ?>
      <p class="text-sm text-zinc-400">Der Hangar ist nicht sichtbar.</p>
    <?php elseif (!$groups): ?>
      <p class="text-sm text-zinc-400">Leer.</p>
    <?php else: ?>
      <?php foreach (Constants::ITEM_KINDS as $kind): if (!isset($groups[$kind])) { continue; } ?>
        <div class="space-y-2">
          <h3 class="text-sm font-medium text-zinc-300"><?= e(Constants::KIND_LABELS[$kind]) ?></h3>
          <ul class="divide-y divide-zinc-800 rounded border border-zinc-800 text-sm">
            <?php foreach ($groups[$kind] as $e): ?>
              <li class="flex justify-between p-2">
                <?php if ($e['catalogItem']): ?>
                  <a href="/catalog/<?= e(strtolower($e['kind'])) ?>/<?= e(rawurlencode($e['catalogItem']['slug'])) ?>" class="hover:underline"><?= (int) $e['quantity'] ?>× <?= e(Hangar::entryName($e)) ?></a>
                <?php else: ?>
                  <span><?= (int) $e['quantity'] ?>× <?= e(Hangar::entryName($e)) ?></span>
                <?php endif; ?>
                <span class="text-zinc-400"><?= $e['lti'] ? 'LTI' : '' ?></span>
              </li>
            <?php endforeach; ?>
          </ul>
        </div>
      <?php endforeach; ?>
    <?php endif; ?>
  </section>
</div>
