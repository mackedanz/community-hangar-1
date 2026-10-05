<?php /** @var list<array<string,mixed>> $feed */ ?>
<section class="space-y-3">
  <h2 class="text-lg font-semibold">Neueste Aktivitäten</h2>
  <?php if (!$feed): ?>
    <p class="text-sm text-zinc-400">Noch nichts los.</p>
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
