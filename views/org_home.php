<?php
/** @var array{slug:string,canPlan:bool} $org */
/** @var list<array<string,mixed>> $feed */
/** @var ?array<string,mixed> $stats */
/** @var array{today:list<array<string,mixed>>,tomorrow:list<array<string,mixed>>} $days */
/** @var ?array<string,mixed> $next */
/** @var DateTimeImmutable $now */
use Hangar\EventTime;

$nf = fn ($n) => number_format((float) $n, 0, ',', '.');
$eventsUrl = '/o/' . $org['slug'] . '/events';
$tiles = [];
if ($stats) {
    $tiles = ['Mitglieder' => $stats['members'], 'Schiffe' => $stats['totalShips'], 'Modelle' => $stats['uniqueModels'], 'Flugbereit' => $stats['flightReady']];
}
$dayBox = [['Heute', $days['today']], ['Morgen', $days['tomorrow']]];
?>
<div class="space-y-8">
  <?php if ($tiles): ?>
    <dl class="grid grid-cols-2 gap-3 lg:grid-cols-4">
      <?php foreach ($tiles as $label => $value): ?>
        <div class="rounded border border-cyan-800/40 border-l-2 border-l-cyan-500 bg-teal-950/50 px-4 py-3">
          <dd class="text-3xl font-bold tabular-nums"><?= e($nf($value)) ?></dd>
          <dt class="text-[11px] font-medium uppercase tracking-wider text-zinc-400"><?= e($label) ?></dt>
        </div>
      <?php endforeach; ?>
    </dl>
  <?php endif; ?>

  <section class="space-y-3">
    <div class="flex items-baseline justify-between gap-2">
      <h2 class="text-lg font-semibold">Anstehende Termine</h2>
      <a href="<?= e($eventsUrl) ?>" class="text-sm text-indigo-400 hover:underline">Zu den Terminen →</a>
    </div>
    <div class="grid gap-3 md:grid-cols-2">
      <?php foreach ($dayBox as [$label, $list]): ?>
        <div class="space-y-2 rounded border border-zinc-800 p-3">
          <h3 class="text-xs font-medium uppercase tracking-wider text-zinc-400"><?= e($label) ?></h3>
          <?php if (!$list): ?>
            <p class="text-sm text-zinc-500">Nichts geplant.</p>
          <?php endif; ?>
          <?php foreach ($list as $ev):
              $running = $ev['endsAt'] && $ev['startsAt'] <= $now && $ev['endsAt'] >= $now; ?>
            <a href="<?= e($eventsUrl . '/' . $ev['id']) ?>" class="flex items-center gap-3 rounded border <?= $running ? 'border-green-500' : 'border-indigo-500/60' ?> bg-zinc-950/40 px-3 py-2 hover:border-indigo-400">
              <span class="w-24 shrink-0 text-sm font-semibold <?= $running ? 'text-green-500' : 'text-indigo-400' ?>"><?= e(EventTime::formatTime($ev['startsAt'])) ?><?= $ev['endsAt'] ? ' – ' . e(EventTime::formatTime($ev['endsAt'])) : '' ?></span>
              <span class="min-w-0 flex-1">
                <span class="block truncate text-sm font-medium"><?= e($ev['title']) ?></span>
                <span class="block text-xs text-zinc-500"><?= $running ? 'läuft gerade · ' : '' ?><?= (int) $ev['yes'] ?> ✓ · <?= (int) $ev['shipCount'] ?> Schiffe</span>
              </span>
            </a>
          <?php endforeach; ?>
        </div>
      <?php endforeach; ?>
    </div>
    <?php if (!$days['today'] && !$days['tomorrow']): ?>
      <p class="text-sm text-zinc-400"><?php if ($next): ?>Nächster Termin: <a href="<?= e($eventsUrl . '/' . $next['id']) ?>" class="font-medium text-zinc-100 hover:underline"><?= e($next['title']) ?></a>, <?= e(EventTime::format($next['startsAt'])) ?>.<?php else: ?>Es sind keine Termine geplant.<?php endif; ?></p>
    <?php endif; ?>
  </section>

  <section class="space-y-3">
    <h2 class="text-lg font-semibold">News</h2>
    <?php if (!$feed): ?>
      <p class="text-sm text-zinc-400">Noch keine neuen Mitglieder oder Errungenschaften.</p>
    <?php else: ?>
      <ul class="divide-y divide-zinc-800 rounded border border-zinc-800 text-sm">
        <?php foreach ($feed as $e): ?>
          <li class="flex flex-wrap justify-between gap-2 p-3">
            <span><a href="/members/<?= e($e['userId']) ?>" class="font-medium hover:underline"><?= e($e['userName'] ?? 'Unbekannt') ?></a> <?= e($e['text']) ?></span>
            <span class="text-zinc-500"><?= e($e['at']->setTimezone(new DateTimeZone('Europe/Berlin'))->format('d.m.Y')) ?></span>
          </li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>
  </section>
</div>
