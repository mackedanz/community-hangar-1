<?php
/** @var string $content */
/** @var \Hangar\Viewer|null $viewer */
/** @var bool $serverAdmin */
/** @var string|null $csrf */
/** @var bool|null $wide breiter Inhaltsbereich (Kachelraster); Logo und Navigation bleiben immer schmal */
$wide = !empty($wide);
$maxW = $wide ? 'max-w-[1800px]' : 'max-w-5xl';
$viewer = $viewer ?? null;
$serverAdmin = $serverAdmin ?? false;
$csrf = $csrf ?? '';
$title = isset($title) && $title !== '' ? $title . ' · Community-Hangar' : 'Community-Hangar';
$link = 'text-zinc-400 hover:text-zinc-100';
$privacyUrl = \Hangar\Env::get('LEGAL_PRIVACY_URL');
$imprintUrl = \Hangar\Env::get('LEGAL_IMPRINT_URL');
?><!DOCTYPE html>
<html lang="de" class="h-full antialiased">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($title) ?></title>
<meta name="description" content="Star-Citizen-Besitz tracken und mit der Community teilen">
<link rel="icon" href="/favicon.ico">
<script>(function(){try{var t=localStorage.getItem("theme");if(t!=="light"&&t!=="dark"){t=window.matchMedia("(prefers-color-scheme: light)").matches?"light":"dark"}document.documentElement.dataset.theme=t}catch(e){document.documentElement.dataset.theme="dark"}})()</script>
<link rel="stylesheet" href="/css/app.css">
</head>
<body class="min-h-full flex flex-col bg-zinc-950 text-zinc-100">
<div class="border-b border-zinc-800">
  <div class="mx-auto flex max-w-5xl justify-center px-4 py-3">
    <a href="/" title="Community-Hangar"><img src="/logo.png" alt="Explorer Germany" width="80" height="80" class="h-20 w-20"></a>
  </div>
</div>
<header class="border-b border-zinc-800">
  <nav class="mx-auto flex max-w-5xl flex-wrap items-center gap-x-6 gap-y-2 px-4 py-3 text-sm">
    <a href="/" class="font-semibold">Community-Hangar</a>
    <a href="/catalog" class="<?= $link ?>">Katalog</a>
    <?php if ($viewer): ?>
      <?php if (count($viewer->orgs) === 1): $o = $viewer->orgs[0]; ?>
        <a href="/o/<?= e($o['slug']) ?>" class="flex items-center gap-2 <?= $link ?>">
          <?= \Hangar\Http\View::partial('partials/org_icon', ['name' => $o['name'], 'iconUrl' => $o['iconUrl'], 'size' => 'h-5 w-5']) ?>
          <?= e($o['name']) ?>
        </a>
      <?php elseif (count($viewer->orgs) > 1): ?>
        <details class="relative" data-org-menu>
          <summary class="cursor-pointer <?= $link ?>">Orgas ▾</summary>
          <ul class="absolute left-0 z-10 mt-2 w-56 rounded border border-zinc-700 bg-zinc-900 py-1 shadow-lg">
            <?php foreach ($viewer->orgs as $o): ?>
              <li>
                <a href="/o/<?= e($o['slug']) ?>" class="flex items-center gap-2 px-3 py-2 hover:bg-zinc-800">
                  <?= \Hangar\Http\View::partial('partials/org_icon', ['name' => $o['name'], 'iconUrl' => $o['iconUrl'], 'size' => 'h-5 w-5']) ?>
                  <?= e($o['name']) ?>
                </a>
              </li>
            <?php endforeach; ?>
          </ul>
        </details>
      <?php endif; ?>
      <a href="/hangar" class="<?= $link ?>">Mein Hangar</a>
      <a href="/sync" class="<?= $link ?>">RSI-Sync</a>
      <a href="/settings" class="<?= $link ?>">Einstellungen</a>
      <?php if (!empty($serverAdmin)): ?>
        <a href="/admin" class="text-amber-400 hover:text-amber-300">Server-Admin</a>
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
<main class="mx-auto w-full <?= $maxW ?> flex-1 px-4 py-8">
  <?php if (!empty($flash)): ?>
    <p role="status" class="mb-6 rounded border p-3 text-sm <?= $flash['t'] === 'error' ? 'border-red-700 text-red-400' : 'border-green-700 text-green-400' ?>"><?= e($flash['m']) ?></p>
  <?php endif; ?>
  <?= $content ?>
</main>
<footer class="border-t border-zinc-800 py-4 text-center text-xs text-zinc-500">
  Inoffizielles Community-Projekt ·
  <?php if ($privacyUrl): ?>
    <a href="<?= e($privacyUrl) ?>" target="_blank" rel="noopener noreferrer" class="hover:text-zinc-300">Datenschutz</a>
  <?php else: ?>
    <a href="/datenschutz" class="hover:text-zinc-300">Datenschutz</a>
  <?php endif; ?>
  <?php if ($imprintUrl): ?>
    · <a href="<?= e($imprintUrl) ?>" target="_blank" rel="noopener noreferrer" class="hover:text-zinc-300">Impressum</a>
  <?php endif; ?>
</footer>
<script src="/js/app.js" defer></script>
<script src="/js/sync-receive.js" defer></script>
</body>
</html>
