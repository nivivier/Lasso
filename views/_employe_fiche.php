<?php
// La fiche d'un employé, sans le décor de page. Sortie de views/employe.php
// pour que la VUE DEUX COLONNES (?p=employes&vue=duo) rende exactement la
// même fiche dans sa colonne de droite — une seule fiche à tenir, deux
// endroits où elle paraît. Même procédé que _structure_fiche.php.
/** @var array $emp */ /** @var array $fiches */
?>
<?php // Pas de retour vers la liste dans le volet droit de la vue deux
      // colonnes : elle est juste à gauche (views/_duo.php, $duoVolet). ?>
<?php if (empty($duoVolet)): ?><?= lien_retour_contextuel('?p=employes', 'Employés') ?><?php endif; ?>
<?php if (($_GET['err'] ?? '') === 'fiches'): ?><p class="err">Impossible de supprimer : cet employé a des fiches de salaire.</p><?php endif; ?>
<?php if (($_GET['err_avatar'] ?? '') !== ''): ?><p class="err"><?= e((string) $_GET['err_avatar']) ?></p><?php endif; ?>
<div class="page-head page-head-bande">
    <h1 class="titre-avatar">
        <?php // La pastille au format du titre : c'est ici qu'on la règle, autant
              // la voir en grand. Le bouton n'apparaît qu'avec le droit d'écriture. ?>
        <span class="avatar-titre"><?= avatar_initiales(
            $emp['prenom'] . ' ' . $emp['nom'],
            (string) ($emp['avatar_couleur'] ?? ''),
            (string) ($emp['avatar_photo'] ?? '')
        ) ?><?php if (peut_ecrire('salaires')): ?>
            <button type="button" class="avatar-regler" data-show="avatar-panneau"
                    title="Changer la pastille" aria-label="Changer la pastille"><?= icon('pencil') ?></button>
        <?php endif; ?></span>
        <?= e($emp['prenom'] . ' ' . $emp['nom']) ?>
        <?php if (!$emp['actif']): ?><span class="badge muted-badge">inactif</span><?php endif; ?>
    </h1>
    <?php if (peut_ecrire('salaires')): ?>
    <div class="head-actions">
        <a class="btn ghost" href="?p=employe_form&id=<?= (int) $emp['id'] ?>"><?= icon('pencil') ?> Modifier l'employé</a>
        <?php // Pas de suppression ici : elle vit sur l'écran de modification,
              // et « Modifier » ferme le groupe (docs/UI.md § 1). ?>
    </div>
    <?php endif; ?>
</div>

<?php if (peut_ecrire('salaires')): ?>
<div class="card mb-22 avatar-panneau" id="avatar-panneau" hidden>
    <div class="card-head-row">
        <h2 class="mt-0">Pastille d'identité</h2>
        <button type="button" class="btn ghost icon-only" data-hide="avatar-panneau"
                title="Fermer" aria-label="Fermer"><?= icon('x') ?></button>
    </div>
    <p class="muted small">Elle apparaît devant le nom dans les listes. Sans réglage, sa couleur est déduite
        du nom — elle est donc déjà stable et différente d'un employé à l'autre.</p>

    <form method="post" action="?p=employe_photo" class="avatar-couleurs">
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="id" value="<?= (int) $emp['id'] ?>">
        <input type="hidden" name="action" value="couleur">
        <?php // Le bouton EST le choix : cliquer envoie, pas de « valider » séparé
              // pour six pastilles. La première remet la teinte déduite du nom. ?>
        <button type="submit" name="couleur" value="" class="avatar-choix avatar-choix-auto<?= trim((string) ($emp['avatar_couleur'] ?? '')) === '' ? ' on' : '' ?>"
                title="Couleur déduite du nom" aria-label="Couleur déduite du nom"><?= icon('sparkles') ?></button>
        <?php foreach (AVATAR_TEINTES as $teinte): ?>
            <button type="submit" name="couleur" value="<?= e($teinte) ?>"
                    class="avatar-choix<?= strtolower((string) ($emp['avatar_couleur'] ?? '')) === $teinte ? ' on' : '' ?>"
                    style="background: <?= e($teinte) ?>" title="<?= e($teinte) ?>"
                    aria-label="Couleur <?= e($teinte) ?>"></button>
        <?php endforeach; ?>
    </form>

    <h3 class="sub">Photo</h3>
    <p class="muted small">Une photo remplace la couleur. Choisissez un fichier, cadrez, puis enregistrez.</p>
    <div class="avatar-photo-zone">
        <label class="btn ghost btn-sm avatar-fichier">
            <?= icon('image') ?> Choisir une image
            <input type="file" accept="image/png,image/jpeg,image/webp" id="avatar-fichier" hidden>
        </label>
        <?php if (trim((string) ($emp['avatar_photo'] ?? '')) !== ''): ?>
        <form method="post" action="?p=employe_photo" class="d-inline"
              data-confirm="Retirer la photo ? La pastille reviendra à sa couleur.">
            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="id" value="<?= (int) $emp['id'] ?>">
            <input type="hidden" name="action" value="photo_supprimer">
            <button type="submit" class="btn danger btn-sm"><?= icon('trash') ?> Retirer la photo</button>
        </form>
        <?php endif; ?>
    </div>

    <?php // Le cadreur n'apparaît qu'une fois un fichier choisi. ?>
    <form method="post" action="?p=employe_photo" id="avatar-crop" hidden>
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="id" value="<?= (int) $emp['id'] ?>">
        <input type="hidden" name="action" value="photo">
        <input type="hidden" name="photo_data" id="avatar-photo-data">
        <div class="avatar-crop-zone"><img id="avatar-crop-img" alt=""></div>
        <div class="form-actions">
            <button type="button" class="btn ghost btn-sm" id="avatar-crop-annuler"><?= icon('x') ?> Annuler</button>
            <button type="submit" class="btn btn-sm"><?= icon('save') ?> Enregistrer la photo</button>
        </div>
    </form>
</div>

<?php // Cropper.js, bundlé dans le dépôt (pas de CDN), chargé sur les seules
      // pages qui recadrent une image — celle-ci et ?p=projets, pour l'icône
      // d'un projet. Voir CLAUDE.md, § Stack. ?>
<link rel="stylesheet" href="assets/vendor/cropperjs/cropper.min.css">
<script src="assets/vendor/cropperjs/cropper.min.js"></script>
<script nonce="<?= e(csp_nonce()) ?>">
(function () {
    var fichier = document.getElementById('avatar-fichier');
    var form    = document.getElementById('avatar-crop');
    var img     = document.getElementById('avatar-crop-img');
    var champ   = document.getElementById('avatar-photo-data');
    if (!fichier || !form || !img || typeof Cropper === 'undefined') { return; }
    var cropper = null;

    function fermer() {
        if (cropper) { cropper.destroy(); cropper = null; }
        form.hidden = true;
        img.removeAttribute('src');
        fichier.value = '';
    }

    // Le cadrage et la vignette viennent de assets/app.js : ils sont partagés
    // avec l'icône d'un projet, et ne doivent pas diverger.
    fichier.addEventListener('change', function () {
        if (cropper) { cropper.destroy(); cropper = null; }
        lassoRecadrageOuvrir(fichier, img, function () { form.hidden = false; })
            .then(function (c) { cropper = c; });
    });

    document.getElementById('avatar-crop-annuler').addEventListener('click', fermer);

    form.addEventListener('submit', function (e) {
        var vignette = lassoRecadrageVignette(cropper);
        if (vignette === '') { e.preventDefault(); return; }
        champ.value = vignette;
    });
})();
</script>
<?php endif; ?>

<div class="card mb-22">
    <?php $adresse = trim($emp['rue'] . ($emp['rue'] && $emp['npa_localite'] ? ', ' : '') . $emp['npa_localite']); ?>
    <dl class="info-grid info-grid-4">
        <div><dt>Date de naissance</dt><dd><?= trim((string) ($emp['date_naissance'] ?? '')) !== '' ? e(date('d.m.Y', strtotime($emp['date_naissance']))) : '—' ?></dd></div>
        <div><dt>Numéro AVS</dt><dd><?= e(avs_affiche($emp['numero_avs'])) ?: '—' ?></dd></div>
        <div><dt>E-mail</dt><dd><?= $emp['email'] ? '<a href="mailto:' . e($emp['email']) . '">' . e($emp['email']) . '</a>' : '—' ?></dd></div>
        <div><dt>Adresse</dt><dd><?= $adresse !== '' ? e($adresse) : '—' ?></dd></div>
        <div><dt>Canton</dt><dd><?= e($emp['canton']) ?: '—' ?></dd></div>
        <div><dt>Procédure</dt><dd><?= e($emp['procedure']) ?: '—' ?></dd></div>
        <div><dt>Supplément vacances</dt><dd><?= pct((float) $emp['supplement_vacances']) ?></dd></div>
        <div><dt>Impôt à la source</dt><dd><?= $emp['procedure'] === 'Ordinaire avec impôt à la source' ? pct((float) $emp['impot_source_taux']) : '—' ?></dd></div>
    </dl>
</div>

<div class="page-head">
    <h2 class="mt-0 mb-0">Fiches de salaire</h2>
    <div class="head-actions">
        <?php if ($fiches): ?>
            <a class="btn ghost" href="?p=certificat&employe_id=<?= (int) $emp['id'] ?>"><?= icon('file-text') ?> Certificat de salaire</a>
        <?php endif; ?>
        <?php if (peut_ecrire('salaires')): ?>
        <a class="btn" href="?p=fiche_form&employe_id=<?= (int) $emp['id'] ?>"><?= icon('file-plus') ?><span class="lbl"> Nouvelle fiche</span></a>
        <?php endif; ?>
    </div>
</div>

<?php if (!$fiches): ?>
    <p class="muted">Aucune fiche de salaire pour cet employé.</p>
<?php else: ?>
<?php // Sur téléphone, les mêmes cartes que la liste des fiches (.cartes-fiches),
      // sans la ligne de l'employé : c'est la page. ?>
<div class="table-scroll">
<table class="list list-wide liste-cartes cartes-fiches cartes-fiches-employe">
    <thead>
        <tr><th>Mois</th><th class="num">Brut</th><th class="num">Net</th><th>Paiement</th><th class="num">Coût employeur</th><th class="center">Envoyée</th></tr>
    </thead>
    <tbody>
    <?php foreach ($fiches as $f): $apayer = trim((string) $f['date_paiement']) === '' && !fiche_a_venir($f); ?>
        <tr class="row-link" tabindex="0" role="link" data-href="?p=fiche&id=<?= (int) $f['id'] ?>&depuis=employe:<?= (int) $emp['id'] ?>">
            <td class="col-date"><?= e(mois_nom((int) $f['mois'])) ?> <?= (int) $f['annee'] ?></td>
            <td class="num col-brut"><?= chf((float) $f['salaire_brut']) ?></td>
            <td class="num strong col-net <?= $apayer ? 'net-apayer' : (fiche_a_venir($f) ? 'net-avenir' : '') ?>"><?= chf((float) $f['salaire_net']) ?></td>
            <td class="col-paiement"><?= badge_paiement($f) ?></td>
            <td class="num col-cout"><?= cout_emp_affiche($f) ?></td>
            <td class="center col-envoyee"><?php if (trim((string) ($f['email_envoye_le'] ?? '')) !== ''): ?><span class="mail-sent" title="Envoyée le <?= e(horodatage($f['email_envoye_le'], 'd.m.Y')) ?>"><?= icon('check') ?></span><?php endif; ?></td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
</div>
<?php endif; ?>
