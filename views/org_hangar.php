<?php
/** @var array{id:string,slug:string,name:string} $org */
/** @var array<string,mixed> $all */
/** @var array<string,mixed> $filter */
/** @var array<string,list<string>> $options */
/** @var list<array<string,mixed>> $entries */
/** @var bool $filtering */
/** @var int $totalShips */
use Hangar\FleetFilter;
use Hangar\Http\View;

$labels = ['career' => 'Karriere', 'role' => 'Rolle', 'status' => 'Status', 'sizeLabel' => 'Größenklasse', 'size' => 'Größe'];
$input = 'rounded border border-zinc-700 bg-zinc-900 px-2 py-1 text-sm';
?>
<div class="space-y-6">
  <div class="flex flex-wrap items-end justify-between gap-2">
    <h2 class="text-lg font-semibold">Orga Hangar</h2>
    <p class="text-sm text-zinc-400"><?= (int) $totalShips ?> Schiffe · <?= count($entries) ?> Modelle · <?= (int) $all['memberCount'] ?> Mitglieder mit Schiffen</p>
  </div>

  <?php if ($all['entries']): ?>
    <form method="get" class="flex flex-wrap items-end gap-3 rounded border border-zinc-800 p-3">
      <label class="flex w-full flex-col gap-1 text-xs text-zinc-400 sm:w-64">Suche
        <input type="search" name="q" value="<?= e($filter['q'] ?? '') ?>" maxlength="100" placeholder="Name, Hersteller, Rolle …" class="<?= $input ?>">
      </label>
      <?php foreach ($labels as $key => $label): ?>
        <label class="flex flex-col gap-1 text-xs text-zinc-400"><?= e($label) ?>
          <select name="<?= e($key) ?>" class="<?= $input ?>">
            <option value="">Alle</option>
            <?php foreach ($options[$key] as $v): ?>
              <option value="<?= e($v) ?>"<?= isset($filter[$key]) && mb_strtolower((string) $filter[$key]) === mb_strtolower($v) ? ' selected' : '' ?>><?= e($v) ?></option>
            <?php endforeach; ?>
          </select>
        </label>
      <?php endforeach; ?>
      <label class="flex flex-col gap-1 text-xs text-zinc-400">Crew min.
        <input type="number" name="crewMin" min="0" max="9999" value="<?= e($filter['crewMin'] ?? '') ?>" class="<?= $input ?> w-20">
      </label>
      <label class="flex flex-col gap-1 text-xs text-zinc-400">Crew max.
        <input type="number" name="crewMax" min="0" max="9999" value="<?= e($filter['crewMax'] ?? '') ?>" class="<?= $input ?> w-20">
      </label>
      <button type="submit" class="rounded bg-indigo-600 px-3 py-1 text-sm text-white hover:bg-indigo-500">Filtern</button>
      <?php if ($filtering): ?><a href="/o/<?= e($org['slug']) ?>/hangar" class="py-1 text-sm text-zinc-400 hover:underline">Zurücksetzen</a><?php endif; ?>
      <p class="w-full text-xs text-zinc-500">
        Crew min.: Schiffe mit Platz für mindestens so viele. Crew max.: Schiffe, die mit höchstens
        so vielen flugfähig sind. Schiffe ohne Katalogdaten erscheinen nur ohne Filter.
      </p>
    </form>
  <?php endif; ?>

  <?php if (!$entries && $filtering && $all['entries']): ?>
    <p class="text-zinc-400">Keine Schiffe passen zu diesen Filtern.</p>
  <?php elseif (!$entries): ?>
    <p class="text-zinc-400">Noch keine Schiffe in der Orga. <a href="/sync" class="text-indigo-400 hover:underline">Synchronisiere deinen Hangar mit RSI</a>.</p>
  <?php else: ?>
    <ul class="grid grid-cols-2 gap-3 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-5 xl:grid-cols-6">
      <?php foreach ($entries as $en): ?>
        <?= View::partial('partials/item_tile', [
          'name' => $en['name'],
          'href' => $en['href'],
          'subtitle' => $en['manufacturer'],
          'count' => (int) $en['count'],
          'image' => $en['imageUrl'],
          'notReady' => FleetFilter::notReadyLabel($en['specs']['status'] ?? null),
        ]) ?>
      <?php endforeach; ?>
    </ul>
  <?php endif; ?>

  <p class="text-xs text-zinc-500">
    Anonyme Übersicht: zählt die Schiffe aller Mitglieder von <?= e($org['name']) ?>, unabhängig von deren
    Sichtbarkeits-Einstellung, und zeigt nie, wem ein Schiff gehört.
  </p>
</div>
