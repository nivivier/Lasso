<?php /** @var array $modeles */ /** @var bool $saved */ /** @var ?string $err */
/** @var array $expediteurs */ /** @var string $expediteurDefaut */

// Liste déroulante des expéditeurs possibles (Paramètres → E-mails). L'option
// vide n'est pas « aucun » mais « celui par défaut » : un mailing part toujours
// de quelque part, et ce défaut peut changer sans qu'il faille reprendre chaque
// modèle. Rien à choisir tant qu'aucun expéditeur n'est déclaré — le champ
// disparaît alors plutôt que d'offrir une liste vide.
$optionsExpediteur = function (?int $choisi) use ($expediteurs, $expediteurDefaut): string {
    $h = '<option value="">Par défaut' . ($expediteurDefaut !== '' ? ' — ' . e($expediteurDefaut) : '') . '</option>';
    foreach ($expediteurs as $ex) {
        $h .= '<option value="' . (int) $ex['id'] . '"' . ($choisi === (int) $ex['id'] ? ' selected' : '')
            . '>' . e(mailing_expediteur_libelle($ex)) . '</option>';
    }
    return $h;
};
?>
<?php require __DIR__ . '/_module_tabs.php'; ?>
<?php require __DIR__ . '/_page_head_band.php'; ?>

<?php if ($saved): ?><p class="ok flash">Modèles mis à jour.</p><?php endif; ?>
<?php if ($err === 'nom_pris'): ?><p class="err flash">Renommage impossible : un autre modèle porte déjà ce nom.</p><?php endif; ?>

<?php if (!peut_ecrire('booking')): ?>
<p class="err">Vous n'avez pas les droits d'écriture nécessaires pour cette action.</p>
<?php else: ?>
<div class="card form">
    <div class="section-head mt-0">
        <h2 class="mt-0">Modèles de message</h2>
        <button type="button" class="btn ml-auto" data-show="modele-add"><?= icon('plus') ?> Nouveau modèle</button>
    </div>
    <p class="muted small">Variables disponibles : <code>{{prenom}}</code> <code>{{nom_structure}}</code>.</p>

    <div id="modele-add" hidden class="fieldset-groupe mb-16">
        <form method="post" action="?p=mailing_modeles">
            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="section" value="modele_save">
            <label>Nom du modèle <input name="nom" required placeholder="ex. Relance festivals"></label>
            <?php if ($expediteurs): ?>
            <label>Expéditeur <select name="expediteur_id"><?= $optionsExpediteur(null) ?></select></label>
            <?php endif; ?>
            <label>Sujet <input name="sujet"></label>
            <label>Corps <textarea name="corps" rows="6" placeholder="Bonjour {{prenom}},&#10;&#10;…"></textarea></label>
            <div class="form-actions">
                <button type="submit"><?= icon('save') ?> Enregistrer</button>
                <button type="button" class="btn ghost" data-hide="modele-add"><?= icon('x') ?> Annuler</button>
            </div>
        </form>
    </div>

    <?php if (!$modeles): ?>
        <p class="muted">Aucun modèle pour l'instant.</p>
    <?php else: ?>
        <?php foreach ($modeles as $m): ?>
        <?php $mid = (int) $m['id']; ?>
        <div class="fieldset-groupe mb-16 modele-bloc">
            <?php // Lecture par défaut : on vient d'abord relire un modèle, pas le
                  // réécrire. Le crayon échange ce bloc contre le cadre d'édition
                  // ci-dessous — même dispositif que la carte Contacts d'une fiche
                  // structure (views/_structure_contact_form.php). ?>
            <div class="modele-lu">
                <div class="card-head-row">
                    <strong><?= e($m['nom']) ?></strong>
                    <button type="button" class="btn ghost btn-sm icon-only modele-editer" title="Modifier" aria-label="Modifier le modèle"><?= icon('pencil') ?></button>
                </div>
                <?php if ($expediteurs): ?>
                <?php $expModele = mailing_expediteur($m['expediteur_id'] !== null ? (int) $m['expediteur_id'] : null); ?>
                <p class="muted small mb-8"><span class="modele-etiquette">Expéditeur</span> <?= $expModele ? e(mailing_expediteur_libelle($expModele)) : '<em>par défaut' . ($expediteurDefaut !== '' ? ' — ' . e($expediteurDefaut) : '') . '</em>' ?></p>
                <?php endif; ?>
                <p class="muted small mb-8"><span class="modele-etiquette">Objet</span> <?= $m['sujet'] !== '' ? e($m['sujet']) : '<em>aucun</em>' ?></p>
                <p class="muted small modele-corps mb-0"><?= $m['corps'] !== '' ? e($m['corps']) : '<em>Corps vide.</em>' ?></p>
            </div>

            <form method="post" action="?p=mailing_modeles" class="form cadre-edit modele-edit" hidden>
                <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="section" value="modele_save">
                <?php // L'identifiant, et non plus le seul nom : c'est lui qui permet
                      // de renommer sans créer un second modèle (mailing_modeles.nom
                      // est UNIQUE et servait de clé — voir route_mailing_modeles()). ?>
                <input type="hidden" name="id" value="<?= $mid ?>">
                <div class="cadre-edit-head">
                    <label class="modele-nom-label"><span>Titre du modèle</span><input name="nom" value="<?= e($m['nom']) ?>" required class="modele-nom-champ"></label>
                    <div class="cadre-edit-actions">
                        <button type="button" class="btn ghost btn-sm icon-only modele-annuler" title="Annuler" aria-label="Annuler"><?= icon('x') ?></button>
                        <button type="submit" class="btn btn-sm icon-only" title="Enregistrer" aria-label="Enregistrer"><?= icon('save') ?></button>
                    </div>
                </div>
                <?php if ($expediteurs): ?>
                <label>Expéditeur <select name="expediteur_id"><?= $optionsExpediteur($m['expediteur_id'] !== null ? (int) $m['expediteur_id'] : null) ?></select></label>
                <?php endif; ?>
                <label>Objet <input name="sujet" value="<?= e($m['sujet']) ?>"></label>
                <label>Corps <textarea name="corps" rows="5"><?= e($m['corps']) ?></textarea></label>
                <?php // Supprimer pilote un <form> posé À CÔTÉ de celui-ci
                      // (attribut form=…) : deux formulaires ne s'imbriquent pas. ?>
                <div class="cadre-edit-pied">
                    <button type="submit" form="modele-del-<?= $mid ?>" class="btn danger btn-sm"><?= icon('trash') ?> Supprimer le modèle</button>
                </div>
            </form>
            <form method="post" action="?p=mailing_modeles" id="modele-del-<?= $mid ?>" data-confirm="Supprimer le modèle « <?= e($m['nom']) ?> » ?" hidden>
                <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="section" value="modele_delete">
                <input type="hidden" name="id" value="<?= $mid ?>">
            </form>
        </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

<script nonce="<?= e(csp_nonce()) ?>">
// Le crayon échange le bloc de lecture contre le cadre d'édition, la croix fait
// l'inverse en restaurant les valeurs d'origine (voir lassoInitBlocEdition()).
lassoInitBlocEdition({
    bloc: '.modele-bloc', lecture: '.modele-lu', edition: '.modele-edit',
    ouvrir: '.modele-editer', annuler: '.modele-annuler',
});
</script>
<?php endif; ?>
