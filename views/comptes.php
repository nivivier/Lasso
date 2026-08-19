<?php
/** @var array $comptes */ /** @var array $permissions */ /** @var ?string $err */ /** @var string $emailSaisi */
/** @var ?string $ok */ /** @var ?string $flagErr */ /** @var int $moi */
$flash = [
    'created'     => 'Compte créé.',
    'reset'       => 'Mot de passe réinitialisé.',
    'deleted'     => 'Compte supprimé.',
    'permissions' => 'Droits mis à jour.',
];
$flashErr = [
    'short'      => 'Le mot de passe doit faire au moins ' . PASSWORD_MIN . ' caractères.',
    'self'       => 'Vous ne pouvez pas supprimer votre propre compte (utilisez « Mon compte »).',
    'last'       => 'Impossible de supprimer le dernier compte.',
    'last_admin' => "Impossible : il doit toujours rester au moins un administrateur (écriture sur « Cœur »).",
];
?>
<?php require __DIR__ . '/_param_tabs.php'; ?>
<?php if ($ok && isset($flash[$ok])): ?><p class="ok flash"><?= e($flash[$ok]) ?></p><?php endif; ?>
<?php if ($flagErr && isset($flashErr[$flagErr])): ?><p class="err flash"><?= e($flashErr[$flagErr]) ?></p><?php endif; ?>
<?php if ($err): ?><p class="err"><?= e($err) ?></p><?php endif; ?>

<?php foreach ($comptes as $c): ?>
<form method="post" action="?p=compte_permissions" id="perm-form-<?= (int) $c['id'] ?>">
    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
    <input type="hidden" name="id" value="<?= (int) $c['id'] ?>">
</form>
<?php endforeach; ?>

<div class="card">
    <h2 class="mt-0">Comptes existants <?= info_tip(
        "Droits d'accès par module : lecture (consultation) ou écriture (modification complète). "
        . 'Un compte avec écriture sur « Cœur » est administrateur (gestion des comptes et des '
        . 'permissions comprise) — il doit toujours en rester au moins un.'
    ) ?></h2>
    <div class="table-scroll">
    <table class="list perm-table">
        <thead>
            <tr>
                <th>E-mail</th>
                <?php foreach (PERMISSION_MODULES as $m): ?>
                    <th class="perm-col"><?= e($m === 'coeur' ? MODULE_COEUR['label'] : MODULES[$m]['label']) ?></th>
                <?php endforeach; ?>
                <th>Créé le</th>
                <th class="col-petit">Réinitialiser le mot de passe</th>
                <th class="actions"></th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($comptes as $c):
            $estMoi  = (int) $c['id'] === $moi;
            $niveaux = $permissions[(int) $c['id']];
            $formId  = 'perm-form-' . (int) $c['id'];
        ?>
            <tr>
                <td>
                    <?= e($c['email']) ?>
                    <?php if ($estMoi): ?> <span class="badge muted-badge">vous</span><?php endif; ?>
                    <?php if (($niveaux['coeur'] ?? null) === 'ecriture'): ?> <span class="badge ok-badge">admin</span><?php endif; ?>
                </td>
                <?php foreach (PERMISSION_MODULES as $m): $val = $niveaux[$m] ?? ''; $lib = $m === 'coeur' ? MODULE_COEUR['label'] : MODULES[$m]['label']; ?>
                <td class="perm-col">
                    <div class="perm-toggle" role="group" aria-label="<?= e($lib . ' — ' . $c['email']) ?>">
                        <button type="button" class="perm-btn <?= $val === '' ? 'on' : '' ?>" data-val="" title="Aucun accès (<?= e($lib) ?>)" aria-label="Aucun accès"><?= icon('eye-off') ?></button>
                        <button type="button" class="perm-btn <?= $val === 'lecture' ? 'on' : '' ?>" data-val="lecture" title="Lecture (<?= e($lib) ?>)" aria-label="Lecture"><?= icon('eye') ?></button>
                        <button type="button" class="perm-btn <?= $val === 'ecriture' ? 'on' : '' ?>" data-val="ecriture" title="Écriture (<?= e($lib) ?>)" aria-label="Écriture"><?= icon('pencil') ?></button>
                        <input type="hidden" name="niveaux[<?= e($m) ?>]" form="<?= $formId ?>" value="<?= e($val) ?>">
                    </div>
                </td>
                <?php endforeach; ?>
                <td class="muted small nowrap"><?= e(date('d.m.Y', strtotime((string) $c['cree_le']))) ?></td>
                <td>
                    <form method="post" action="?p=compte_reset" class="reset-form"
                          data-confirm="Réinitialiser le mot de passe de <?= e($c['email']) ?> ?">
                        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                        <input type="hidden" name="id" value="<?= (int) $c['id'] ?>">
                        <input type="password" name="nouveau_mot_de_passe" placeholder="Nouveau mot de passe"
                               autocomplete="new-password" minlength="<?= PASSWORD_MIN ?>" required>
                        <button type="submit" class="btn ghost btn-sm">Réinitialiser</button>
                    </form>
                </td>
                <td class="actions">
                    <?php if (!$estMoi): ?>
                    <form method="post" action="?p=compte_delete" class="d-inline"
                          data-confirm="Supprimer définitivement le compte <?= e($c['email']) ?> ?">
                        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                        <input type="hidden" name="id" value="<?= (int) $c['id'] ?>">
                        <button type="submit" class="btn danger icon-only btn-sm" title="Supprimer le compte" aria-label="Supprimer le compte"><?= icon('trash') ?></button>
                    </form>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
</div>

<div class="card form mt-22">
    <h2 class="mt-0">Ajouter un compte <?= info_tip(
        "Le nouveau compte n'a aucun droit par défaut — attribuez-lui des droits ci-dessus une fois créé."
    ) ?></h2>
    <form method="post" action="?p=comptes" autocomplete="off">
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
        <div class="grid2">
            <label>E-mail <input name="email" type="email" value="<?= e($emailSaisi) ?>" placeholder="personne@exemple.ch" required></label>
            <label>Mot de passe <input name="mot_de_passe" type="password" autocomplete="new-password"
                       minlength="<?= PASSWORD_MIN ?>" placeholder="au moins <?= PASSWORD_MIN ?> caractères" required></label>
        </div>
        <div class="form-actions"><button type="submit"><?= icon('user-plus') ?> Créer le compte</button></div>
    </form>
</div>
<script nonce="<?= e(csp_nonce()) ?>">
document.querySelectorAll('.perm-toggle').forEach(group => {
    const hidden = group.querySelector('input[type=hidden]');
    group.querySelectorAll('.perm-btn').forEach(btn => {
        btn.addEventListener('click', () => {
            if (btn.classList.contains('on')) return;
            group.querySelectorAll('.perm-btn').forEach(b => b.classList.remove('on'));
            btn.classList.add('on');
            hidden.value = btn.dataset.val;
            hidden.form.requestSubmit();
        });
    });
});
</script>
