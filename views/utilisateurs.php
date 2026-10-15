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
            <?php // Cinq colonnes, et non quinze : les droits ne sont plus une
                  // colonne par module mais un résumé, réglé dans le panneau
                  // d'édition (views/_compte_ligne.php). ?>
            <tr>
                <th>Compte</th>
                <th>Droits</th>
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
                <td colspan="5">
                    <form method="post" action="?p=utilisateurs" autocomplete="off" class="compte-ajout-form"
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
// Pose un niveau sur UN module : la classe « on » suit le bouton cliqué, la
// valeur suit dans le champ caché qui part avec le formulaire.
function poserNiveau(group, val) {
    if (!group) return;
    group.querySelectorAll('.perm-btn').forEach(b => b.classList.toggle('on', b.dataset.val === val));
    group.querySelector('input[type=hidden]').value = val;
}
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
        // La ligne porte DEUX formulaires — celui du compte et celui de la
        // suppression, qui ne peut pas s'imbriquer dedans. D'où la classe :
        // désigner « le premier » marcherait tant que l'ordre ne bouge pas.
        const form = edition.querySelector('.compte-form');
        form.reset();
        // ⚠️ form.reset() ne rend RIEN aux champs cachés : pour un
        // input[type=hidden], la propriété `value` et l'attribut `value` sont
        // le même emplacement, donc poser un niveau a déjà effacé la valeur
        // d'origine. Annuler laissait les onze droits sur le dernier clic, et
        // il suffisait d'enregistrer plus tard pour que ça parte en base. Le
        // niveau du serveur est donc gardé à part, dans data-initial.
        edition.querySelectorAll('.perm-toggle').forEach(group => {
            poserNiveau(group, group.dataset.initial || '');
        });
        edition.hidden = true;
        edition.previousElementSibling.hidden = false;
        return;
    }
    // Les droits ne s'enregistrent plus tout seuls : ils partent avec le reste
    // de la ligne, au clic sur « Enregistrer ».
    const niveau = e.target.closest('.perm-btn');
    if (niveau && !niveau.classList.contains('on')) {
        poserNiveau(niveau.closest('.perm-toggle'), niveau.dataset.val);
    }
    // Le raccourci : onze modules au même niveau, c'est onze clics pour un
    // geste qui n'en vaut qu'un (un compte en lecture seule, un administrateur
    // à qui l'on rend tout). Il ne fait que poser les boutons — rien n'est
    // enregistré avant « Enregistrer », comme pour un clic module par module.
    const tout = e.target.closest('.perm-tout');
    if (tout) {
        tout.closest('.compte-edit-cell')
            .querySelectorAll('.perm-toggle')
            .forEach(group => poserNiveau(group, tout.dataset.val));
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
