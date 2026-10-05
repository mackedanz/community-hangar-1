<?php
/** @var array{slug:string,canPlan:bool} $org */
/** @var string $month */
/** @var string $monthKey */
/** @var string $prevKey */
/** @var string $nextKey */
/** @var list<?int> $cells */
/** @var array<string,list<array<string,mixed>>> $byDay */
/** @var string $today */
/** @var list<array<string,mixed>> $upcoming */
use Hangar\EventTime;

$base = '/o/' . $org['slug'] . '/events';
$weekdays = ['Mo', 'Di', 'Mi', 'Do', 'Fr', 'Sa', 'So'];
?>
<div class="space-y-6">
  <div class="flex flex-wrap items-center justify-between gap-2">
    <h2 class="text-lg font-semibold">Planung</h2>
    <?php if ($org['canPlan']): ?>
      <a href="<?= e($base) ?>/new" class="rounded bg-indigo-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-indigo-500">Event anlegen</a>
    <?php endif; ?>
  </div>

  <section class="space-y-2">
    <div class="flex items-center gap-3">
      <a href="<?= e($base) ?>?month=<?= e($prevKey) ?>" class="rounded border border-zinc-700 px-2 py-1 text-sm hover:bg-zinc-800" aria-label="Vorheriger Monat">←</a>
      <h3 class="w-40 text-center font-medium"><?= e($month) ?></h3>
      <a href="<?= e($base) ?>?month=<?= e($nextKey) ?>" class="rounded border border-zinc-700 px-2 py-1 text-sm hover:bg-zinc-800" aria-label="Nächster Monat">→</a>
      <a href="<?= e($base) ?>" class="text-sm text-zinc-400 hover:underline">Heute</a>
    </div>

    <div class="grid grid-cols-7 gap-px overflow-hidden rounded border border-zinc-800 bg-zinc-800 text-sm">
      <?php foreach ($weekdays as $d): ?><div class="bg-zinc-900 px-2 py-1 text-center text-xs text-zinc-400"><?= e($d) ?></div><?php endforeach; ?>
      <?php foreach ($cells as $day):
          $k = $day ? $monthKey . '-' . str_pad((string) $day, 2, '0', STR_PAD_LEFT) : '';
          $list = $day ? ($byDay[$k] ?? []) : []; ?>
        <div class="min-h-20 bg-zinc-950 p-1 <?= $day ? '' : 'opacity-40' ?>">
          <?php if ($day): ?>
            <div class="mb-1 text-xs <?= $k === $today ? 'font-bold text-indigo-400' : 'text-zinc-500' ?>"><?= (int) $day ?></div>
            <ul class="space-y-1">
              <?php foreach ($list as $ev): ?>
                <li><a href="<?= e($base . '/' . $ev['id']) ?>" title="<?= e($ev['title']) ?>" class="block truncate rounded px-1 py-0.5 text-xs hover:bg-indigo-500/30 <?= $ev['cancelled'] ? 'bg-zinc-800 text-zinc-500 line-through' : 'bg-indigo-500/20' ?>"><?= e($ev['title']) ?></a></li>
              <?php endforeach; ?>
            </ul>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>
    </div>
  </section>

  <section class="space-y-2">
    <h3 class="font-medium">Kommende Events</h3>
    <?php if (!$upcoming): ?>
      <p class="text-sm text-zinc-400">Keine anstehenden Events.<?= $org['canPlan'] ? ' Lege das erste mit „Event anlegen“ an.' : '' ?></p>
    <?php else: ?>
      <ul class="divide-y divide-zinc-800 rounded border border-zinc-800">
        <?php foreach ($upcoming as $ev): ?>
          <li class="flex flex-wrap items-center justify-between gap-2 p-3">
            <a href="<?= e($base . '/' . $ev['id']) ?>" class="font-medium hover:underline"><?= e($ev['title']) ?></a>
            <span class="text-sm text-zinc-400"><?= e(EventTime::format($ev['startsAt'])) ?></span>
          </li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>
  </section>
</div>
