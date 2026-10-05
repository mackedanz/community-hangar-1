<?php
/** @var list<array<string,mixed>> $guilds */
/** @var ?string $problem */
/** @var string $csrf */
use Hangar\Http\View;

$input = 'w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2';
?>
<div class="max-w-md space-y-6">
  <div>
    <h1 class="text-2xl font-bold">Orga anlegen</h1>
    <p class="mt-1 text-sm text-zinc-400">
      Jede Orga gehört zu einem Discord-Server. Mitglied ist, wer auf dem Server ist und die
      Mitgliedsrolle hat. Anlegen können nur Server-Admins (Owner, Administrator oder „Server
      verwalten“). Andere Orgas sehen eure Daten nie.
    </p>
  </div>

  <?php if ($problem): ?>
    <p class="text-sm text-red-400"><?= e($problem) ?></p>
  <?php elseif (!$guilds): ?>
    <p class="text-sm text-zinc-400">
      Du bist auf keinem Discord-Server Admin, für den es noch keine Orga gibt. Ist eure Orga
      schon registriert, prüfe deine Mitgliedschaft in den <a href="/settings" class="text-indigo-400 hover:underline">Einstellungen</a>.
    </p>
  <?php else: ?>
    <form method="post" action="/orgs/new" class="space-y-4">
      <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
      <label class="block space-y-1">
        <span class="text-sm text-zinc-400">Discord-Server</span>
        <select name="guildId" required class="<?= $input ?>">
          <?php foreach ($guilds as $g): ?><option value="<?= e($g['id']) ?>"><?= e($g['name']) ?></option><?php endforeach; ?>
        </select>
      </label>
      <label class="block space-y-1">
        <span class="text-sm text-zinc-400">Name der Orga</span>
        <input name="name" required minlength="2" maxlength="60" class="<?= $input ?>">
      </label>
      <?= View::partial('partials/role_fields', ['values' => [], 'name' => 'memberRoleIds', 'nameField' => 'memberRoleNames', 'labels' => [], 'legend' => 'Rollen-IDs der Mitgliedsrollen']) ?>
      <?= View::partial('partials/role_help') ?>
      <button class="rounded bg-indigo-600 px-4 py-2 font-medium hover:bg-indigo-500">Orga anlegen</button>
    </form>
  <?php endif; ?>
</div>
