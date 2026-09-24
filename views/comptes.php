<?php
/** @var array $comptes */ /** @var array $permissions */ /** @var ?string $err */ /** @var string $emailSaisi */
/** @var ?string $ok */ /** @var ?string $flagErr */ /** @var int $moi */
$flash = [
    'created'     => 'Compte créé.',
    'modified'    => 'Compte modifié.',
    'reset'       => 'Compte modifié, et mot de passe réinitialisé.',
    'deleted'     => 'Compte supprimé.',
    'permissions' => 'Droits mis à jour.',
];
$flashErr = [
    'short'      => 'Le mot de passe doit faire au moins ' . PASSWORD_MIN . ' caractères.',
    'self'       => 'Vous ne pouvez pas supprimer votre propre compte (utilisez « Mon compte »).',
    'last'       => 'Impossible de supprimer le dernier compte.',
    'last_admin' => "Impossible : il doit toujours rester au moins un administrateur (écriture sur « Cœur »).",
    'email'      => 'Adresse e-mail invalide — le compte n\'a pas été modifié.',
    'email_pris' => 'Cette adresse e-mail est déjà utilisée par un autre compte.',
];
?>
<?php require __DIR__ . '/_param_tabs.php'; ?>
<?php if ($ok && isset($flash[$ok])): ?><p class="ok flash"><?= e($flash[$ok]) ?></p><?php endif; ?>
<?php if ($flagErr && isset($flashErr[$flagErr])): ?><p class="err flash"><?= e($flashErr[$flagErr]) ?></p><?php endif; ?>
<?php if ($err): ?><p class="err"><?= e($err) ?></p><?php endif; ?>

<div class="card">
    <div class="card-head-row">
        <h2 class="mt-0">Comptes <?= info_tip(
            "Droits d'accès par module : lecture (consultation) ou écriture (modification complète). "
            . 'Un compte avec écriture sur « Cœur » est administrateur (gestion des comptes et des '
            . 'permissions comprise) — il doit toujours en rester au moins un.'
        ) ?></h2>
        <?php // Ajouter est une action de cette carte, pas une seconde carte :
              // le bouton ouvre une ligne de saisie dans le tableau, et la
              // création s'y insère sans recharger (docs/UI.md § 5). ?>
        <div class="head-actions">
            <button type="button" class="btn ghost icon-only" data-show="compte-ajout-row"
                    data-focus="input[name=email]" title="Nouveau compte" aria-label="Nouveau compte"><?= icon('user-plus') ?></button>
        </div>
    </div>
    <div class="table-scroll">
    <table class="list perm-table">
        <thead>
            <tr>
                <th>Compte</th>
                <?php foreach (PERMISSION_MODULES as $m): ?>
                    <th class="perm-col"><?= e($m === 'coeur' ? MODULE_COEUR['label'] : MODULES[$m]['label']) ?></th>
                <?php endforeach; ?>
                <th>Dernière connexion</th>
                <th>Créé le</th>
                <th class="actions"></th>
            </tr>
        </thead>
        <tbody id="comptes-corps">
        <?php foreach ($comptes as $c): ?>
            <?php $niveaux = $permissions[(int) $c['id']]; ?>
            <?php require __DIR__ . '/_compte_ligne.php'; ?>
        <?php endforeach; ?>
        </tbody>
        <?php // Ligne de saisie, dépliée par le bouton de la barre d'action. Elle
              // vit DANS le tableau — un compte s'ajoute là où on lit les autres
              // — mais dans son PROPRE <tbody> : le compte créé s'insère à la fin
              // de celui des comptes (data-ajout), donc avant cette ligne-ci. ?>
        <tbody>
            <tr id="compte-ajout-row" hidden>
                <td colspan="<?= 3 + count(PERMISSION_MODULES) ?>">
                    <form method="post" action="?p=comptes" autocomplete="off" class="compte-ajout-form"
                          data-ajout="#comptes-corps" data-ajout-message="Compte créé.">
                        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                        <label>E-mail <input name="email" type="email" value="<?= e($emailSaisi) ?>" placeholder="personne@exemple.ch" required></label>
                        <label>Mot de passe <input name="mot_de_passe" type="password" autocomplete="new-password"
                                   minlength="<?= PASSWORD_MIN ?>" placeholder="au moins <?= PASSWORD_MIN ?> caractères" required></label>
                        <button type="submit" class="btn btn-sm"><?= icon('save') ?> Créer</button>
                        <button type="button" class="btn ghost btn-sm icon-only" data-hide="compte-ajout-row" title="Annuler" aria-label="Annuler"><?= icon('x') ?></button>
                        <p class="muted small mb-0">Le nouveau compte n'a aucun droit : ouvrez sa ligne pour lui en donner.</p>
                    </form>
                </td>
            </tr>
        </tbody>
    </table>
    </div>
</div>

<script nonce="<?= e(csp_nonce()) ?>">
// Le crayon REMPLACE la ligne de lecture par celle d'édition ; la croix rend
// aux champs leur valeur d'origine et rétablit la lecture — annuler annule
// vraiment, droits compris.
// Écouteurs DÉLÉGUÉS, et non posés ligne à ligne : un compte créé sans recharger
// arrive après eux, et doit avoir son crayon comme les autres (docs/UI.md § 5).
document.addEventListener('click', e => {
    const crayon = e.target.closest('.compte-edit-btn');
    if (crayon) {
        const lecture = crayon.closest('tr');
        const edition = lecture.nextElementSibling;
        lecture.hidden = true;
        edition.hidden = false;
        edition.querySelector('input[name="prenom"]')?.focus();
        return;
    }
    const croix = e.target.closest('.compte-cancel-btn');
    if (croix) {
        const edition = croix.closest('tr');
        // Le <form> vit dans la première cellule de la ligne ; ses champs des
        // autres colonnes s'y rattachent par form=, qui donne son identifiant.
        const form = document.getElementById(edition.querySelector('[form]').getAttribute('form'));
        form.reset();
        // form.reset() rend leur valeur aux champs cachés, pas la classe « on »
        // aux boutons : on la repose depuis la valeur rétablie.
        edition.querySelectorAll('.perm-toggle').forEach(group => {
            const val = group.querySelector('input[type=hidden]').value;
            group.querySelectorAll('.perm-btn').forEach(b => b.classList.toggle('on', b.dataset.val === val));
        });
        edition.hidden = true;
        edition.previousElementSibling.hidden = false;
        return;
    }
    // Les droits ne s'enregistrent plus tout seuls : ils partent avec le reste
    // de la ligne, au clic sur « Enregistrer ».
    const niveau = e.target.closest('.perm-btn');
    if (niveau && !niveau.classList.contains('on')) {
        const group = niveau.closest('.perm-toggle');
        group.querySelectorAll('.perm-btn').forEach(b => b.classList.remove('on'));
        niveau.classList.add('on');
        group.querySelector('input[type=hidden]').value = niveau.dataset.val;
    }
});
// Le compte créé s'insère à la fin de la liste : la ligne de saisie se referme,
// comme si l'on avait refermé le panneau soi-même. C'est la page qui le fait —
// data-ajout-ferme retirerait le <form> et laisserait sa rangée vide derrière.
document.addEventListener('lasso:ligne-ajoutee', e => {
    if (!document.getElementById('comptes-corps')?.contains(e.detail)) return;
    const ligne = document.getElementById('compte-ajout-row');
    if (ligne) ligne.hidden = true;
});
</script>
