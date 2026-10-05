<?php
/** @var array<string,mixed> $item */
/** @var string $kind */
/** @var ?string $imageSrc */
/** @var array<string,string> $rows */
/** @var ?string $webUrl */
/** @var list<string> $modules */
/** @var list<array<string,mixed>> $owners */
/** @var ?\Hangar\Viewer $viewer */
use Hangar\Http\View;
?>
<div class="space-y-6">
  <a href="/catalog?kind=<?= e($kind) ?>" class="text-sm text-zinc-400 hover:text-zinc-100">← Zum Katalog</a>

  <div class="grid gap-6 md:grid-cols-2">
    <div class="aspect-[4/3] overflow-hidden rounded bg-zinc-900">
      <?php if ($imageSrc): ?><img src="<?= e($imageSrc) ?>" alt="<?= e($item['name']) ?>" class="h-full w-full object-cover"><?php endif; ?>
    </div>

    <div class="space-y-4">
      <div>
        <h1 class="text-2xl font-bold"><?= e($item['name']) ?></h1>
        <p class="text-zinc-400"><?= e($item['manufacturer'] ?? 'Unbekannter Hersteller') ?></p>
      </div>

      <dl class="grid grid-cols-[auto_1fr] gap-x-6 gap-y-1 text-sm">
        <?php foreach ($rows as $label => $value): ?>
          <div class="contents"><dt class="text-zinc-400"><?= e($label) ?></dt><dd><?= e($value) ?></dd></div>
        <?php endforeach; ?>
      </dl>

      <?php if ($modules): ?>
        <div class="text-sm">
          <h2 class="mb-1 font-semibold">Module</h2>
          <ul class="list-inside list-disc text-zinc-300">
            <?php foreach ($modules as $m): ?><li><?= e($m) ?></li><?php endforeach; ?>
          </ul>
        </div>
      <?php endif; ?>

      <?php if ($webUrl): ?>
        <a href="<?= e($webUrl) ?>" target="_blank" rel="noopener noreferrer" class="inline-block text-sm text-indigo-400 hover:underline">Mehr Details ansehen</a>
      <?php endif; ?>
    </div>
  </div>

  <?php if ($viewer && in_array($kind, ['SHIP', 'ARMOR'], true)): ?>
    <section class="space-y-2">
      <h2 class="text-lg font-semibold">Zu meinem Hangar</h2>
      <?= View::partial('partials/add_item_form', ['catalogItemId' => $item['id'], 'csrf' => $viewer->csrf, 'return' => '/catalog/' . strtolower($kind) . '/' . rawurlencode($item['slug'])]) ?>
    </section>
  <?php endif; ?>

  <section class="space-y-2">
    <h2 class="text-lg font-semibold">Wer aus deinen Orgas besitzt das?</h2>
    <?php if (!$viewer): ?>
      <p class="text-sm text-zinc-400"><a href="/login" class="text-indigo-400 hover:underline">Melde dich an</a>, um zu sehen, wer aus deiner Orga dieses Schiff besitzt.</p>
    <?php elseif (!$owners): ?>
      <p class="text-sm text-zinc-400">Niemand, den du sehen darfst.</p>
    <?php else: ?>
      <ul class="grid gap-2 sm:grid-cols-2 md:grid-cols-3">
        <?php foreach ($owners as $o): ?>
          <li>
            <a href="/members/<?= e($o['userId']) ?>" class="flex items-center justify-between rounded border border-zinc-800 p-2 text-sm hover:border-zinc-600">
              <span><?= e($o['name'] ?? 'Unbekannt') ?></span>
              <span class="text-zinc-400"><?= (int) $o['quantity'] ?>×<?= $o['lti'] ? ' · LTI' : '' ?></span>
            </a>
          </li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>
  </section>
</div>
