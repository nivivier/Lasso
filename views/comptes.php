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
// Nom affiché : l'identité si elle est renseignée, sinon l'adresse — un compte
// créé sans prénom ni nom ne doit pas se présenter comme une ligne vide.
$nomAffiche = function (array $c): string {
    $n = trim(trim((string) ($c['prenom'] ?? '')) . ' ' . trim((string) ($c['nom'] ?? '')));
    return $n !== '' ? $n : (string) $c['email'];
};
// Droits en lecture : une seule icône par module, celle du niveau accordé.
// Rien de cliquable — la ligne de lecture se lit, elle ne se modifie pas.
$niveauIcone = ['' => 'eye-off', 'lecture' => 'eye', 'ecriture' => 'pencil'];
$niveauTexte = ['' => 'aucun accès', 'lecture' => 'lecture', 'ecriture' => 'écriture'];
$dateHeure = function ($v): string {
    $v = trim((string) $v);
    return $v === '' ? '' : date('d.m.Y à H:i', strtotime($v));
};
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
<?php // Un <form> ne peut pas contenir des <td> de colonnes différentes : il vit
      // donc hors du tableau, et chaque champ de la ligne d'édition s'y rattache
      // par son attribut form=. C'est ce qui permet aux droits de rester dans
      // LEUR colonne pendant l'édition. ?>
<?php foreach ($comptes as $c): ?>
<form method="post" action="?p=compte_modifier" id="compte-form-<?= (int) $c['id'] ?>" autocomplete="off" hidden>
    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
    <input type="hidden" name="id" value="<?= (int) $c['id'] ?>">
</form>
<?php endforeach; ?>
<?php if ($ok && isset($flash[$ok])): ?><p class="ok flash"><?= e($flash[$ok]) ?></p><?php endif; ?>
<?php if ($flagErr && isset($flashErr[$flagErr])): ?><p class="err flash"><?= e($flashErr[$flagErr]) ?></p><?php endif; ?>
<?php if ($err): ?><p class="err"><?= e($err) ?></p><?php endif; ?>

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
                <th>Compte</th>
                <?php foreach (PERMISSION_MODULES as $m): ?>
                    <th class="perm-col"><?= e($m === 'coeur' ? MODULE_COEUR['label'] : MODULES[$m]['label']) ?></th>
                <?php endforeach; ?>
                <th>Dernière connexion</th>
                <th>Créé le</th>
                <th class="actions"></th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($comptes as $c):
            $estMoi  = (int) $c['id'] === $moi;
            $niveaux = $permissions[(int) $c['id']];
        ?>
            <tr class="compte-row">
                <td>
                    <div class="compte-identite">
                        <span class="compte-nom"><?= e($nomAffiche($c)) ?></span>
                        <?php if ($estMoi): ?><span class="badge muted-badge">vous</span><?php endif; ?>
                        <?php if (($niveaux['coeur'] ?? null) === 'ecriture'): ?><span class="badge ok-badge">admin</span><?php endif; ?>
                    </div>
                    <?php if ($nomAffiche($c) !== $c['email']): ?>
                        <div class="muted small"><?= e($c['email']) ?></div>
                    <?php endif; ?>
                </td>
                <?php foreach (PERMISSION_MODULES as $m): $val = $niveaux[$m] ?? ''; $lib = $m === 'coeur' ? MODULE_COEUR['label'] : MODULES[$m]['label']; ?>
                <td class="perm-col">
                    <span class="perm-lecture perm-<?= $val === '' ? 'aucun' : ($val === 'lecture' ? 'lecture-niv' : 'ecriture') ?>"
                          title="<?= e($lib . ' — ' . $niveauTexte[$val]) ?>"><?= icon($niveauIcone[$val]) ?></span>
                </td>
                <?php endforeach; ?>
                <?php $derniere = $dateHeure($c['derniere_connexion_le'] ?? ''); ?>
                <td class="muted small nowrap"><?= $derniere !== '' ? e($derniere) : '<span class="muted">jamais</span>' ?></td>
                <td class="muted small nowrap"><?= e(date('d.m.Y', strtotime((string) $c['cree_le']))) ?></td>
                <td class="actions nowrap">
                    <button type="button" class="btn ghost btn-sm icon-only compte-edit-btn" title="Modifier" aria-label="Modifier le compte"><?= icon('pencil') ?></button>
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
            <?php // Édition : une ligne pleine largeur, ouverte par le crayon. Rien
                  // n'est modifiable tant qu'elle est fermée. ?>
            <tr class="compte-edit-row" hidden>
                <?php $fid = 'compte-form-' . (int) $c['id']; ?>
                <td class="compte-edit-identite">
                    <label>Prénom <input form="<?= $fid ?>" name="prenom" value="<?= e((string) ($c['prenom'] ?? '')) ?>" autocomplete="off"></label>
                    <label>Nom <input form="<?= $fid ?>" name="nom" value="<?= e((string) ($c['nom'] ?? '')) ?>" autocomplete="off"></label>
                    <label>E-mail <input form="<?= $fid ?>" name="email" type="email" value="<?= e((string) $c['email']) ?>" required autocomplete="off"></label>
                    <p class="muted small mb-0">Changer l'adresse invalide les liens de réinitialisation en attente.</p>
                    <label>Nouveau mot de passe
                        <input form="<?= $fid ?>" type="password" name="nouveau_mot_de_passe" autocomplete="new-password"
                               minlength="<?= PASSWORD_MIN ?>" placeholder="laisser vide = inchangé">
                    </label>
                </td>
                <?php foreach (PERMISSION_MODULES as $m): $val = $niveaux[$m] ?? ''; $lib = $m === 'coeur' ? MODULE_COEUR['label'] : MODULES[$m]['label']; ?>
                <td class="perm-col">
                    <div class="perm-toggle" role="group" aria-label="<?= e($lib . ' — ' . $c['email']) ?>">
                        <button type="button" class="perm-btn <?= $val === '' ? 'on' : '' ?>" data-val="" title="Aucun accès (<?= e($lib) ?>)" aria-label="Aucun accès (<?= e($lib) ?>)"><?= icon('eye-off') ?></button>
                        <button type="button" class="perm-btn <?= $val === 'lecture' ? 'on' : '' ?>" data-val="lecture" title="Lecture (<?= e($lib) ?>)" aria-label="Lecture (<?= e($lib) ?>)"><?= icon('eye') ?></button>
                        <button type="button" class="perm-btn <?= $val === 'ecriture' ? 'on' : '' ?>" data-val="ecriture" title="Écriture (<?= e($lib) ?>)" aria-label="Écriture (<?= e($lib) ?>)"><?= icon('pencil') ?></button>
                        <input form="<?= $fid ?>" type="hidden" name="niveaux[<?= e($m) ?>]" value="<?= e($val) ?>">
                    </div>
                </td>
                <?php endforeach; ?>
                <?php // Enregistrer et Annuler à l'extrême droite de la ligne, sous le
                      // crayon qui a ouvert l'édition. ?>
                <td colspan="3" class="compte-edit-actions">
                    <button type="submit" form="<?= $fid ?>" class="btn btn-sm"><?= icon('save') ?> Enregistrer</button>
                    <button type="button" class="btn ghost btn-sm icon-only compte-cancel-btn" title="Annuler" aria-label="Annuler"><?= icon('x') ?></button>
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
// Le crayon REMPLACE la ligne de lecture par celle d'édition ; la croix rend
// aux champs leur valeur d'origine et rétablit la lecture — annuler annule
// vraiment, droits compris.
document.querySelectorAll('.compte-edit-btn').forEach(btn => {
    btn.addEventListener('click', () => {
        const lecture = btn.closest('tr');
        const edition = lecture.nextElementSibling;
        lecture.hidden = true;
        edition.hidden = false;
        edition.querySelector('input[name="prenom"]')?.focus();
    });
});
document.querySelectorAll('.compte-cancel-btn').forEach(btn => {
    btn.addEventListener('click', () => {
        const edition = btn.closest('tr');
        // Les champs vivent dans les cellules, le <form> hors du tableau : on le
        // retrouve par l'attribut form= de n'importe lequel d'entre eux.
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
    });
});
// Les droits ne s'enregistrent plus tout seuls : ils partent avec le reste de
// la ligne, au clic sur « Enregistrer ».
document.querySelectorAll('.perm-toggle').forEach(group => {
    const hidden = group.querySelector('input[type=hidden]');
    group.querySelectorAll('.perm-btn').forEach(btn => {
        btn.addEventListener('click', () => {
            if (btn.classList.contains('on')) return;
            group.querySelectorAll('.perm-btn').forEach(b => b.classList.remove('on'));
            btn.classList.add('on');
            hidden.value = btn.dataset.val;
        });
    });
});
</script>
