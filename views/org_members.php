<?php
/** @var list<array<string,mixed>> $members */
/** @var array<string,mixed> $org */
/** @var ?string $rsiOrgName */
/** Rahmenfarbe und Tooltip je RSI-Status (null = kein RSI-Abgleich eingerichtet). */
$rsiLook = [
    'main' => ['border-emerald-600/70 hover:border-emerald-400', 'In der RSI-Orga (Hauptorga)'],
    'affiliate' => ['border-cyan-600/70 hover:border-cyan-400', 'In der RSI-Orga als Affiliate'],
    'out' => ['border-yellow-600/70 hover:border-yellow-400', 'Nicht in der Mitgliederliste der RSI-Orga gefunden'],
    'unknown' => ['border-red-700/70 hover:border-red-500', 'RSI-Zugehörigkeit nicht prüfbar (verborgen oder kein Handle ableitbar)'],
];
$hasRsi = $members !== [] && $members[0]['rsiStatus'] !== null;
?>
<div class="space-y-4">
  <h2 class="text-lg font-semibold">Mitglieder <span class="text-sm font-normal text-zinc-400">(<?= count($members) ?>)</span></h2>
  <p class="text-xs text-zinc-500">Hier erscheint, wer sich in der App angemeldet hat und auf Discord als Mitglied der Orga bestätigt ist.</p>
  <?php if ($hasRsi): ?>
    <p class="flex flex-wrap items-center gap-x-4 gap-y-1 text-xs text-zinc-400">
      <span>Rahmen = Zugehörigkeit zur RSI-Orga<?= !empty($rsiOrgName) ? ' „' . e($rsiOrgName) . '“' : '' ?>:</span>
      <span class="flex items-center gap-1"><i class="inline-block h-3 w-3 rounded-sm border border-emerald-600"></i> Mitglied</span>
      <span class="flex items-center gap-1"><i class="inline-block h-3 w-3 rounded-sm border border-cyan-600"></i> Affiliate</span>
      <span class="flex items-center gap-1"><i class="inline-block h-3 w-3 rounded-sm border border-yellow-600"></i> nicht gefunden</span>
      <span class="flex items-center gap-1"><i class="inline-block h-3 w-3 rounded-sm border border-red-700"></i> nicht prüfbar</span>
    </p>
  <?php endif; ?>
  <ul class="grid gap-3 sm:grid-cols-2 md:grid-cols-3">
    <?php foreach ($members as $m):
      [$rsiBorder, $rsiTitle] = $rsiLook[$m['rsiStatus'] ?? ''] ?? ['border-zinc-800 hover:border-zinc-600', null]; ?>
      <li>
        <a href="/members/<?= e($m['id']) ?>" class="flex items-center gap-3 rounded border <?= $rsiBorder ?> p-3"<?= $rsiTitle !== null ? ' title="' . e($rsiTitle) . '"' : '' ?>>
          <?php if ($m['image']): ?><img src="<?= e($m['image']) ?>" alt="" class="h-10 w-10 rounded-full"><?php else: ?><div class="h-10 w-10 rounded-full bg-zinc-800"></div><?php endif; ?>
          <div class="min-w-0">
            <div class="truncate font-medium"><?= e($m['name'] ?? 'Unbekannt') ?><?php if ($m['isAdmin']): ?><span class="ml-2 text-xs text-amber-400">Admin</span><?php endif; ?></div>
            <div class="text-xs text-zinc-400">
              <?= $m['shipCount'] === null ? 'Hangar privat' : e($m['shipCount'] . ' Schiffe') ?>
              <?= $m['achievementCount'] !== null ? e(' · ' . $m['achievementCount'] . ' Errungenschaften') : '' ?>
            </div>
          </div>
        </a>
      </li>
    <?php endforeach; ?>
  </ul>
</div>
