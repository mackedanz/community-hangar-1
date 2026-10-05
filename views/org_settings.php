<?php
/** @var array<string,mixed> $full */
/** @var list<string> $memberRoleIds */
/** @var list<string> $plannerRoleIds */
/** @var array<string,string> $roleLabels */
/** @var string $csrf */
use Hangar\Http\View;

$input = 'w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2';
?>
<div class="max-w-2xl space-y-8">
  <section class="space-y-3">
    <h2 class="text-lg font-semibold">Orga verwalten</h2>
    <form method="post" action="/orgs/update" class="space-y-4">
      <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
      <input type="hidden" name="orgId" value="<?= e($full['id']) ?>">
      <label class="block space-y-1">
        <span class="text-sm text-zinc-400">Name der Orga</span>
        <input name="name" required minlength="2" maxlength="60" value="<?= e($full['name']) ?>" class="<?= $input ?>">
      </label>
      <?= View::partial('partials/role_fields', ['values' => $memberRoleIds, 'name' => 'memberRoleIds', 'nameField' => 'memberRoleNames', 'labels' => $roleLabels, 'legend' => 'Rollen-IDs der Mitgliedsrollen']) ?>
      <?= View::partial('partials/role_fields', ['values' => $plannerRoleIds, 'name' => 'plannerRoleIds', 'nameField' => 'plannerRoleNames', 'labels' => $roleLabels, 'legend' => 'Rollen-IDs der Planer (dürfen Events anlegen)']) ?>
      <p class="text-xs text-zinc-500">
        Wer eine dieser Rollen hat, darf in der Planung Events anlegen und bearbeiten. Orga-Admins
        dürfen das immer. Ohne Eintrag planen nur Admins.
      </p>
      <?= View::partial('partials/role_help') ?>
      <p class="text-xs text-zinc-500">Nach einer Änderung der Rollen werden alle Mitglieder bei ihrem nächsten Seitenaufruf neu geprüft.</p>
      <button class="rounded bg-indigo-600 px-4 py-2 font-medium hover:bg-indigo-500">Speichern</button>
    </form>
  </section>

  <?php if (!empty($botReady) || !empty($allowed)): ?>
  <section class="space-y-3 border-t border-zinc-800 pt-6">
    <h2 class="text-lg font-semibold">Zugangsliste (Onboarding-Bot)</h2>
    <p class="text-sm text-zinc-400">
      Wer eine der Rollen oben hat, wird vom Bot auf die Liste gesetzt (stündlich und per Knopf).
      <?= !empty($gate) ? 'Nur Personen auf dieser Liste können sich anmelden.' : 'Die Anmeldung ist noch für alle offen (LOGIN_REQUIRES_ALLOWLIST ist aus).' ?>
      Die Rollen kannst du hier oder im Discord mit <b>/einrichten</b> ändern.
    </p>
    <?php if (!empty($botReady)): ?>
      <form method="post" action="/orgs/sync-allowlist">
        <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
        <input type="hidden" name="orgId" value="<?= e($full['id']) ?>">
        <button class="rounded border border-zinc-600 px-3 py-2 text-sm hover:bg-zinc-800">Mitglieder jetzt abgleichen</button>
        <?php if (!empty($full['allowlist_synced_at'])): ?>
          <span class="ml-2 text-xs text-zinc-500">Letzter Abgleich: <?= e(dt(\Hangar\Time::parse($full['allowlist_synced_at']))) ?></span>
        <?php endif; ?>
      </form>
    <?php endif; ?>
    <details class="text-sm">
      <summary class="cursor-pointer text-zinc-300"><?= count($allowed) ?> Personen auf der Liste</summary>
      <ul class="mt-2 grid gap-1 sm:grid-cols-2">
        <?php foreach ($allowed as $m): ?>
          <li class="flex items-center gap-2">
            <?php if (!empty($m['avatar_url'])): ?><img src="<?= e($m['avatar_url']) ?>" alt="" class="h-6 w-6 rounded-full" loading="lazy"><?php endif; ?>
            <span><?= e($m['name'] ?? $m['discord_id']) ?></span>
            <?php if ($m['fixed']): ?><span class="text-xs text-zinc-500">(fest)</span><?php endif; ?>
          </li>
        <?php endforeach; ?>
      </ul>
    </details>
  </section>
  <?php endif; ?>

  <section class="space-y-3 border-t border-zinc-800 pt-6">
    <h2 class="text-lg font-semibold text-red-400">Orga löschen</h2>
    <p class="text-sm text-zinc-400">
      Entfernt die Orga und alle Mitgliedschaften aus der App. Konten und Hangars der
      Mitglieder bleiben erhalten. Tippe zur Bestätigung <b>LÖSCHEN</b>.
    </p>
    <form method="post" action="/orgs/delete" class="space-y-2">
      <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
      <input type="hidden" name="orgId" value="<?= e($full['id']) ?>">
      <div class="flex gap-2">
        <input name="confirm" autocomplete="off" class="rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm">
        <button class="rounded bg-red-700 px-3 py-2 text-sm font-medium hover:bg-red-600">Orga löschen</button>
      </div>
    </form>
  </section>
</div>
