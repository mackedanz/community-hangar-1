<?php
/** @var array{slug:string,canPlan:bool} $org */
/** @var string $today */
/** @var list<array{key:string,label:string,events:list<array<string,mixed>>}> $timeline */
use Hangar\EventTime;

$base = '/o/' . $org['slug'] . '/events';
$wdFmt = new IntlDateFormatter('de_DE', IntlDateFormatter::NONE, IntlDateFormatter::NONE, 'Europe/Berlin', IntlDateFormatter::GREGORIAN, 'EEE');
$nowUtc = new DateTimeImmutable('now', new DateTimeZone('UTC'));
$monthNow = substr($today, 0, 7);
?>
<div class="space-y-6">
  <div class="flex flex-wrap items-center justify-between gap-2">
    <h2 class="text-lg font-semibold">Planung</h2>
    <?php if ($org['canPlan']): ?>
      <a href="<?= e($base) ?>/new" class="rounded bg-indigo-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-indigo-500">Event anlegen</a>
    <?php endif; ?>
  </div>

  <section class="space-y-2">
    
    <div class="overflow-x-auto pb-3">
      <div class="flex min-w-max items-start gap-3">
        <?php foreach ($timeline as $col): ?>
          <div class="w-72 shrink-0 space-y-2">
            <div class="border-b-2 border-indigo-500 bg-indigo-500/20 px-2 py-1 text-sm font-semibold <?= $col['key'] === $monthNow ? 'text-indigo-400' : '' ?>"><?= e($col['label']) ?></div>
            <?php foreach ($col['events'] as $ev):
                $mins = $ev['endsAt'] ? (int) round(($ev['endsAt']->getTimestamp() - $ev['startsAt']->getTimestamp()) / 60) : 0;
                $dur = $mins > 0 ? 'ca. ' . rtrim(rtrim(number_format($mins / 60, 1, ',', ''), '0'), ',') . ' h' : '';
                $past = $ev['startsAt'] < $nowUtc && (!$ev['endsAt'] || $ev['endsAt'] < $nowUtc);
                $dayNo = substr(EventTime::dayKey($ev['startsAt']), 8, 2);
                $wd = $wdFmt->format($ev['startsAt']); ?>
              <a href="<?= e($base . '/' . $ev['id']) ?>" class="flex overflow-hidden rounded border transition hover:border-indigo-400 <?= $ev['cancelled'] ? 'border-zinc-800 opacity-60' : ($past ? 'border-zinc-800 opacity-75' : 'border-indigo-500/60') ?>">
                <div class="flex w-14 shrink-0 flex-col items-center justify-center <?= $ev['cancelled'] ? 'bg-zinc-800' : 'bg-indigo-500/25' ?>">
                  <span class="text-[10px] uppercase text-zinc-400"><?= e($wd) ?></span>
                  <span class="text-2xl font-bold leading-none"><?= e($dayNo) ?></span>
                </div>
                <div class="min-w-0 flex-1 space-y-0.5 bg-zinc-950/70 px-2 py-1.5">
                  <div class="truncate text-sm font-medium <?= $ev['cancelled'] ? 'line-through' : '' ?>"><?= e($ev['title']) ?></div>
                  <div class="text-xs text-zinc-400"><?= e(EventTime::formatTime($ev['startsAt'])) ?><?= $ev['endsAt'] ? ' – ' . e(EventTime::formatTime($ev['endsAt'])) : '' ?><?= $dur ? ' · ' . e($dur) : '' ?><?= $ev['cancelled'] ? ' · abgesagt' : '' ?></div>
                  <div class="flex items-center justify-between gap-2 text-[11px] text-zinc-500">
                    <span title="Zusagen" class="shrink-0 whitespace-nowrap"><?= (int) $ev['yes'] ?> ✓ · <?= (int) $ev['shipCount'] ?> Schiffe</span>
                    <span class="truncate">von <?= e($ev['by']) ?></span>
                  </div>
                </div>
              </a>
            <?php endforeach; ?>
            <?php if (!$col['events']): ?><p class="px-1 text-xs text-zinc-600">Keine Events</p><?php endif; ?>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
  </section>
</div>
