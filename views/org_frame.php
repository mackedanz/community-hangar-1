<?php
/** @var array{id:string,slug:string,name:string,iconUrl:?string,role:string,canPlan:bool} $org */
/** @var string $inner */
/** @var string $active */
use Hangar\Http\View;

$base = '/o/' . $org['slug'];
$tabs = [
    'home' => ['Übersicht', $base],
    'members' => ['Mitglieder', $base . '/members'],
    'hangar' => ['Orga Hangar', $base . '/hangar'],
    'stats' => ['Statistik', $base . '/stats'],
    'events' => ['Planung', $base . '/events'],
];
if ($org['role'] === 'ADMIN') {
    $tabs['settings'] = ['Orga verwalten', $base . '/settings'];
}
?>
<div class="space-y-6">
  <div class="flex flex-wrap items-center gap-x-6 gap-y-2 border-b border-zinc-800 pb-3">
    <a href="<?= e($base) ?>" class="flex items-center gap-2 text-lg font-semibold">
      <?= View::partial('partials/org_icon', ['name' => $org['name'], 'iconUrl' => $org['iconUrl'], 'size' => 'h-8 w-8']) ?>
      <?= e($org['name']) ?>
    </a>
    <nav class="flex gap-4 text-sm">
      <?php foreach ($tabs as $key => [$label, $href]): ?>
        <a href="<?= e($href) ?>" class="<?= $key === $active ? 'text-zinc-100 underline underline-offset-8' : 'text-zinc-400 hover:text-zinc-100' ?>"><?= e($label) ?></a>
      <?php endforeach; ?>
    </nav>
  </div>
  <?= $inner ?>
</div>
