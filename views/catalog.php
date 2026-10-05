<?php
/** @var list<array<string,mixed>> $items */
/** @var string $q */
/** @var string $kind */
/** @var int $page */
/** @var int $pages */
/** @var int $total */
/** @var list<string> $kinds */
use Hangar\Constants;

$href = fn (array $over) => '/catalog?' . http_build_query(array_filter(['kind' => $kind, 'q' => $q] + $over, fn ($v) => $v !== ''));
?>
<div class="space-y-6">
  <h1 class="text-2xl font-bold">Katalog</h1>

  <div class="flex flex-wrap items-center gap-2">
    <?php foreach ($kinds as $k): ?>
      <a href="/catalog?kind=<?= e($k) ?><?= $q !== '' ? '&amp;q=' . e(rawurlencode($q)) : '' ?>" class="rounded px-3 py-1.5 text-sm <?= $k === $kind ? 'bg-indigo-600' : 'bg-zinc-800 hover:bg-zinc-700' ?>"><?= e(Constants::KIND_LABELS[$k]) ?></a>
    <?php endforeach; ?>
    <form class="ml-auto flex gap-2" method="get" action="/catalog">
      <input type="hidden" name="kind" value="<?= e($kind) ?>">
      <input name="q" value="<?= e($q) ?>" placeholder="Suchen…" class="rounded border border-zinc-700 bg-zinc-900 px-3 py-1.5 text-sm">
      <button class="rounded bg-zinc-800 px-3 py-1.5 text-sm hover:bg-zinc-700">Suchen</button>
    </form>
  </div>

  <p class="text-sm text-zinc-400"><?= (int) $total ?> Treffer</p>

  <?php if (!$items): ?>
    <p class="text-zinc-400">Nichts gefunden. Ist der Katalog synchronisiert? (<code>php bin/catalog-sync.php</code>)</p>
  <?php else: ?>
    <ul class="grid grid-cols-2 gap-4 sm:grid-cols-3 md:grid-cols-4">
      <?php foreach ($items as $item): ?>
        <li>
          <a href="/catalog/<?= e(strtolower($item['kind'])) ?>/<?= e(rawurlencode($item['slug'])) ?>" class="block overflow-hidden rounded border border-zinc-800 hover:border-zinc-600">
            <div class="aspect-[4/3] bg-zinc-900">
              <?php if ($item['imageSrc']): ?><img src="<?= e($item['imageSrc']) ?>" alt="" loading="lazy" class="h-full w-full object-cover"><?php endif; ?>
            </div>
            <div class="p-2 text-sm">
              <div class="font-medium"><?= e($item['name']) ?></div>
              <div class="text-xs text-zinc-400"><?= e($item['manufacturer'] ?? '–') ?></div>
            </div>
          </a>
        </li>
      <?php endforeach; ?>
    </ul>
  <?php endif; ?>

  <?php if ($pages > 1): ?>
    <div class="flex items-center gap-4 text-sm">
      <?php if ($page > 1): ?><a href="<?= e($href(['page' => $page - 1])) ?>">← Zurück</a><?php endif; ?>
      <span class="text-zinc-400">Seite <?= (int) $page ?> von <?= (int) $pages ?></span>
      <?php if ($page < $pages): ?><a href="<?= e($href(['page' => $page + 1])) ?>">Weiter →</a><?php endif; ?>
    </div>
  <?php endif; ?>
</div>
