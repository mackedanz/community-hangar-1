<?php /** @var list<array<string,mixed>> $members */ ?>
<div class="space-y-4">
  <h2 class="text-lg font-semibold">Mitglieder <span class="text-sm font-normal text-zinc-400">(<?= count($members) ?>)</span></h2>
  <p class="text-xs text-zinc-500">Hier erscheint, wer sich in der App angemeldet hat und auf Discord als Mitglied der Orga bestätigt ist.</p>
  <ul class="grid gap-3 sm:grid-cols-2 md:grid-cols-3">
    <?php foreach ($members as $m): ?>
      <li>
        <a href="/members/<?= e($m['id']) ?>" class="flex items-center gap-3 rounded border border-zinc-800 p-3 hover:border-zinc-600">
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
