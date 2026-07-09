<?php
/** @var array $comptes */ /** @var ?string $err */ /** @var string $emailSaisi */
/** @var ?string $ok */ /** @var ?string $flagErr */ /** @var int $moi */
$flash = [
    'created' => 'Compte créé.',
    'reset'   => 'Mot de passe réinitialisé.',
    'deleted' => 'Compte supprimé.',
];
$flashErr = [
    'short' => 'Le mot de passe doit faire au moins ' . PASSWORD_MIN . ' caractères.',
    'self'  => 'Vous ne pouvez pas supprimer votre propre compte (utilisez « Mon compte »).',
    'last'  => 'Impossible de supprimer le dernier compte.',
];
?>
<?php require __DIR__ . '/_param_tabs.php'; ?>
<?php if ($ok && isset($flash[$ok])): ?><p class="ok flash"><?= e($flash[$ok]) ?></p><?php endif; ?>
<?php if ($flagErr && isset($flashErr[$flagErr])): ?><p class="err flash"><?= e($flashErr[$flagErr]) ?></p><?php endif; ?>
<?php if ($err): ?><p class="err"><?= e($err) ?></p><?php endif; ?>

<div class="card">
    <h2 class="mt-0">Comptes existants <?= info_tip("Tous les comptes ont les mêmes droits d'accès à l'application.") ?></h2>
    <div class="table-scroll">
    <table class="list">
        <thead>
            <tr><th>E-mail</th><th>Créé le</th><th>Réinitialiser le mot de passe</th><th class="actions"></th></tr>
        </thead>
        <tbody>
        <?php foreach ($comptes as $c): $estMoi = (int) $c['id'] === $moi; ?>
            <tr>
                <td><?= e($c['email']) ?><?php if ($estMoi): ?> <span class="badge">vous</span><?php endif; ?></td>
                <td><?= e(date('d.m.Y', strtotime((string) $c['cree_le']))) ?></td>
                <td>
                    <form method="post" action="?p=compte_reset" class="reset-form"
                          onsubmit="return confirm('Réinitialiser le mot de passe de <?= e($c['email']) ?> ?');">
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
                          onsubmit="return confirm('Supprimer définitivement le compte <?= e($c['email']) ?> ?');">
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
    <h2 class="mt-0">Ajouter un compte</h2>
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
