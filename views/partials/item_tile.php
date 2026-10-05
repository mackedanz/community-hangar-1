<?php
/**
 * Kachel für Schiffe und Ausrüstung (Mein Hangar, Orga Hangar).
 * @var string $name
 * @var ?string $href Link zur Katalogseite
 * @var ?string $subtitle Hersteller bzw. Typ
 * @var ?int $count Stückzahl; null = Katalog (keine Zahl)
 * @var ?string $meta Zusatzzeile statt der Stückzahl (z. B. Größenklasse)
 * @var bool $stretch ganze Kachel anklickbar (Katalog)
 * @var ?string $image
 * @var ?string $tooltip Beschreibung beim Darüberfahren
 * @var bool $lti
 * @var string $actions fertig escaptes HTML (Herkunft, Entfernen), oben rechts
 */
$subtitle = $subtitle ?? null;
$image = $image ?? null;
$tooltip = $tooltip ?? $name;
$lti = $lti ?? false;
$actions = $actions ?? '';
$count = $count ?? null;
$meta = $meta ?? null;
$stretch = $stretch ?? false;
?>
<li class="group relative flex flex-col overflow-hidden rounded border border-cyan-800/60 bg-teal-950/50 transition hover:border-cyan-500/80" title="<?= e($tooltip) ?>">
  <div class="p-2.5<?= $actions !== '' ? ' pr-16' : '' ?>">
    <div class="truncate text-sm font-medium text-zinc-100">
      <?php if ($href): ?><a href="<?= e($href) ?>" class="hover:underline<?= $stretch ? ' after:absolute after:inset-0' : '' ?>"><?= e($name) ?></a><?php else: ?><?= e($name) ?><?php endif; ?>
    </div>
    <div class="truncate text-xs text-zinc-400"><?= e($subtitle ?? '–') ?></div>
    <?php if ($count !== null): ?>
      <div class="mt-1 flex items-baseline gap-2">
        <span class="text-3xl font-light leading-none tabular-nums text-zinc-100"><?= (int) $count ?>×</span>
        <?php if ($lti): ?><span class="rounded bg-cyan-900/60 px-1.5 py-0.5 text-[10px] font-medium text-cyan-200">LTI</span><?php endif; ?>
      </div>
    <?php elseif ($meta): ?>
      <div class="mt-1 truncate text-xs text-cyan-200/80"><?= e($meta) ?></div>
    <?php endif; ?>
  </div>
  <div class="mt-auto aspect-[4/3] w-full bg-black/20">
    <?php if ($image): ?><img src="<?= e($image) ?>" alt="" loading="lazy" class="h-full w-full object-cover"><?php endif; ?>
  </div>
  <?php if ($actions !== ''): ?>
    <div class="absolute right-1.5 top-1.5 flex flex-col items-end gap-1 opacity-70 transition group-hover:opacity-100 group-focus-within:opacity-100"><?= $actions ?></div>
  <?php endif; ?>
</li>
