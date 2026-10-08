<?php
/** @var string $content */
/** @var \Hangar\Viewer|null $viewer */
/** @var bool $serverAdmin */
/** @var string|null $csrf */
/** @var bool|null $wide breiter Inhaltsbereich (Kachelraster); Logo und Navigation bleiben immer schmal */
$wide = !empty($wide);
$bare = !empty($bare);   // /pur/…: nur der Inhalt, ohne Logo, Navigation und Fußzeile
$maxW = $wide ? 'max-w-[1800px]' : 'max-w-5xl';
$brand = \Hangar\Branding::current();
$viewer = $viewer ?? null;
$serverAdmin = $serverAdmin ?? false;
$csrf = $csrf ?? '';
$title = isset($title) && $title !== '' ? $title . ' · Community-Hangar' : 'Community-Hangar';
$link = 'text-zinc-400 hover:text-zinc-100';
// Aktuelle Seite in der Navigation hervorheben (wie die Reiter im Orga-Bereich)
$path = (string) parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);
$here = static fn (string $prefix): bool => $path === $prefix || str_starts_with($path, $prefix . '/');
$navClass = static fn (bool $active, string $idle = 'text-zinc-400 hover:text-zinc-100', string $on = 'text-zinc-100'): string
    => $active ? $on . ' underline underline-offset-8' : $idle;
$cur = static fn (bool $active): string => $active ? ' aria-current="page"' : '';
$privacyUrl = \Hangar\Env::get('LEGAL_PRIVACY_URL');
$imprintUrl = \Hangar\Env::get('LEGAL_IMPRINT_URL');
?><!DOCTYPE html>
<html lang="de" class="h-full antialiased">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($title) ?></title>
<meta name="description" content="Star-Citizen-Besitz tracken und mit der Community teilen">
<link rel="icon" type="image/png" href="<?= e($brand['logoUrl']) ?>">
<script>(function(){try{var t=localStorage.getItem("theme");if(t!=="light"&&t!=="dark"){t=window.matchMedia("(prefers-color-scheme: light)").matches?"light":"dark"}document.documentElement.dataset.theme=t}catch(e){document.documentElement.dataset.theme="dark"}})()</script>
<link rel="stylesheet" href="/css/app.css">
<style><?= \Hangar\Branding::css() ?></style>
</head>
<body class="flex h-dvh flex-col overflow-hidden bg-zinc-950 text-zinc-100">
<?php /* Kopf (Logo, Navigation) und Fußzeile bleiben stehen, nur der Bereich dazwischen scrollt. */ ?>
<?php if (!$bare): ?>
<div class="relative z-20 shrink-0 bg-zinc-950/85">
<div class="border-b border-zinc-800">
  <div class="mx-auto flex max-w-5xl justify-center px-4 py-3">
    <a href="/" title="Community-Hangar"><img src="<?= e($brand['logoUrl']) ?>" alt="Community-Hangar" width="80" height="80" class="h-20 w-20"></a>
  </div>
</div>
<header class="border-b border-zinc-800">
  <nav class="mx-auto flex max-w-5xl flex-wrap items-center gap-x-6 gap-y-2 px-4 py-3 text-sm">
    <a href="/" class="font-semibold">Community-Hangar</a>
    <?php if ($viewer): ?>
      <a href="/catalog" class="<?= $navClass($here('/catalog')) ?>"<?= $cur($here('/catalog')) ?>>Katalog</a>
      <?php if (count($viewer->orgs) === 1): $o = $viewer->orgs[0]; ?>
        <a href="/o/<?= e($o['slug']) ?>" class="flex items-center gap-2 <?= $navClass($here('/o/' . $o['slug'])) ?>"<?= $cur($here('/o/' . $o['slug'])) ?>>
          <?= \Hangar\Http\View::partial('partials/org_icon', ['name' => $o['name'], 'iconUrl' => $o['iconUrl'], 'size' => 'h-5 w-5']) ?>
          <?= e($o['name']) ?>
        </a>
      <?php elseif (count($viewer->orgs) > 1): ?>
        <details class="relative" data-org-menu>
          <summary class="cursor-pointer <?= $navClass($here('/o')) ?>">Orgas ▾</summary>
          <ul class="absolute left-0 z-10 mt-2 w-56 rounded border border-zinc-700 bg-zinc-900 py-1 shadow-lg">
            <?php foreach ($viewer->orgs as $o): ?>
              <li>
                <a href="/o/<?= e($o['slug']) ?>" class="flex items-center gap-2 px-3 py-2 hover:bg-zinc-800<?= $here('/o/' . $o['slug']) ? ' font-semibold text-zinc-100' : '' ?>"<?= $cur($here('/o/' . $o['slug'])) ?>>
                  <?= \Hangar\Http\View::partial('partials/org_icon', ['name' => $o['name'], 'iconUrl' => $o['iconUrl'], 'size' => 'h-5 w-5']) ?>
                  <?= e($o['name']) ?>
                </a>
              </li>
            <?php endforeach; ?>
          </ul>
        </details>
      <?php endif; ?>
      <a href="/hangar" class="<?= $navClass($here('/hangar')) ?>"<?= $cur($here('/hangar')) ?>>Mein Hangar</a>
      <a href="/sync" class="<?= $navClass($here('/sync')) ?>"<?= $cur($here('/sync')) ?>>RSI-Sync</a>
      <a href="/settings" class="<?= $navClass($here('/settings')) ?>"<?= $cur($here('/settings')) ?>>Einstellungen</a>
      <?php if (!empty($serverAdmin)): ?>
        <a href="/admin" class="<?= $navClass($here('/admin'), 'text-amber-400 hover:text-amber-300', 'text-amber-300') ?>"<?= $cur($here('/admin')) ?>>Server-Admin</a>
      <?php endif; ?>
      <span class="ml-auto"><button type="button" data-theme-toggle class="<?= $link ?>"></button></span>
      <form method="post" action="/logout">
        <input type="hidden" name="_csrf" value="<?= e($csrf ?? '') ?>">
        <button class="<?= $link ?>">Abmelden (<?= e($viewer->name) ?>)</button>
      </form>
    <?php else: ?>
      <span class="ml-auto"><button type="button" data-theme-toggle class="<?= $link ?>"></button></span>
      <a href="/login" class="<?= $link ?>">Anmelden</a>
    <?php endif; ?>
  </nav>
</header>
</div>
<?php endif; ?>
<?php /* Nur der Bereich über der Fußzeile scrollt; die Fußzeile bleibt immer sichtbar. */ ?>
<div class="min-h-0 flex-1 overflow-y-auto" id="page-scroll">
<div class="flex min-h-full flex-col">
<main class="mx-auto w-full <?= $maxW ?> flex-1 px-4 py-8">
  <?php if (!empty($flash)): ?>
    <p role="status" class="mb-6 rounded border p-3 text-sm <?= $flash['t'] === 'error' ? 'border-red-700 text-red-400' : 'border-green-700 text-green-400' ?>"><?= e($flash['m']) ?></p>
  <?php endif; ?>
  <?= $content ?>
</main>
</div>
</div>
<?php if (!$bare): ?>
<footer class="shrink-0 border-t border-zinc-800 bg-zinc-950/85 py-3 text-center text-xs text-zinc-500">
  MADE BY THE COMMUNITY ·
  <?php if ($privacyUrl): ?>
    <a href="<?= e($privacyUrl) ?>" target="_blank" rel="noopener noreferrer" class="hover:text-zinc-300">Datenschutz</a>
  <?php else: ?>
    <a href="/datenschutz" class="hover:text-zinc-300">Datenschutz</a>
  <?php endif; ?>
  <?php if ($imprintUrl): ?>
    · <a href="<?= e($imprintUrl) ?>" target="_blank" rel="noopener noreferrer" class="hover:text-zinc-300">Impressum</a>
  <?php endif; ?>
</footer>
<?php endif; ?>
<script src="/js/app.js" defer></script>
<script src="/js/sync-receive.js" defer></script>
</body>
</html>
