<?php /** @var array $emp */ /** @var array $fiches */ ?>
<?php require __DIR__ . '/_module_tabs.php'; ?>
<?php require __DIR__ . '/_page_head_band.php'; ?>

<div class="module-content"><div class="module-content-inner">
<?= lien_retour_contextuel('?p=employes', 'Employés') ?>
<?php if (($_GET['err'] ?? '') === 'fiches'): ?><p class="err">Impossible de supprimer : cet employé a des fiches de salaire.</p><?php endif; ?>
<?php if (($_GET['err_avatar'] ?? '') !== ''): ?><p class="err"><?= e((string) $_GET['err_avatar']) ?></p><?php endif; ?>
<div class="page-head">
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
        <a class="btn ghost" href="?p=employe&id=<?= (int) $emp['id'] ?>"><?= icon('pencil') ?> Modifier l'employé</a>
        <?php if (!$fiches): ?>
            <form method="post" action="?p=employe_delete" data-confirm="Supprimer définitivement cet employé ?" class="d-inline">
                <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="id" value="<?= (int) $emp['id'] ?>">
                <button type="submit" class="btn danger icon-only" title="Supprimer" aria-label="Supprimer l'employé"><?= icon('trash') ?></button>
            </form>
        <?php endif; ?>
    </div>
    <?php endif; ?>
</div>

<?php if (peut_ecrire('salaires')): ?>
<div class="card mb-22 avatar-panneau" id="avatar-panneau" hidden>
    <div class="card-head-row">
        <h2 class="mt-0">Pastille d'identité</h2>
        <button type="button" class="btn ghost btn-sm icon-only" data-hide="avatar-panneau"
                title="Fermer" aria-label="Fermer"><?= icon('x') ?></button>
    </div>
    <p class="muted small">Elle apparaît devant le nom dans les listes. Sans réglage, sa couleur est déduite
        du nom — elle est donc déjà stable et différente d'un employé à l'autre.</p>

    <form method="post" action="?p=employe_avatar" class="avatar-couleurs">
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="id" value="<?= (int) $emp['id'] ?>">
        <input type="hidden" name="action" value="couleur">
        <?php // Le bouton EST le choix : cliquer envoie, pas de « valider » séparé
              // pour six pastilles. La première remet la teinte déduite du nom. ?>
        <button type="submit" name="couleur" value="" class="avatar-choix avatar-choix-auto<?= trim((string) ($emp['avatar_couleur'] ?? '')) === '' ? ' on' : '' ?>"
                title="Couleur déduite du nom"><?= icon('sparkles') ?></button>
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
        <form method="post" action="?p=employe_avatar" class="d-inline"
              data-confirm="Retirer la photo ? La pastille reviendra à sa couleur.">
            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="id" value="<?= (int) $emp['id'] ?>">
            <input type="hidden" name="action" value="photo_supprimer">
            <button type="submit" class="btn danger btn-sm"><?= icon('trash') ?> Retirer la photo</button>
        </form>
        <?php endif; ?>
    </div>

    <?php // Le cadreur n'apparaît qu'une fois un fichier choisi. ?>
    <form method="post" action="?p=employe_avatar" id="avatar-crop" hidden>
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

<?php // Cropper.js, bundlé dans le dépôt (pas de CDN), chargé sur cette seule
      // page — c'est la seule qui recadre une image. Voir CLAUDE.md, § Stack. ?>
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

    fichier.addEventListener('change', function () {
        var f = fichier.files && fichier.files[0];
        if (!f) { return; }
        var lecteur = new FileReader();
        lecteur.onload = function (e) {
            if (cropper) { cropper.destroy(); }
            img.src = e.target.result;
            form.hidden = false;
            // aspectRatio 1 : la pastille est ronde, le cadre doit être carré.
            // viewMode 1 empêche de sortir le cadre de l'image.
            cropper = new Cropper(img, { aspectRatio: 1, viewMode: 1, autoCropArea: 1, background: false });
        };
        lecteur.readAsDataURL(f);
    });

    document.getElementById('avatar-crop-annuler').addEventListener('click', fermer);

    form.addEventListener('submit', function (e) {
        if (!cropper) { e.preventDefault(); return; }
        // 256px : deux fois la plus grande taille d'affichage (la pastille du
        // titre), de quoi rester net sur un écran haute densité sans envoyer
        // la photo d'origine. JPEG 0.85 : quelques dizaines de Ko.
        var toile = cropper.getCroppedCanvas({ width: 256, height: 256, imageSmoothingQuality: 'high' });
        if (!toile) { e.preventDefault(); return; }
        champ.value = toile.toDataURL('image/jpeg', 0.85);
    });
})();
</script>
<?php endif; ?>

<div class="card mb-22">
    <?php $adresse = trim($emp['rue'] . ($emp['rue'] && $emp['npa_localite'] ? ', ' : '') . $emp['npa_localite']); ?>
    <dl class="info-grid info-grid-4">
        <div><dt>Date de naissance</dt><dd><?= trim((string) ($emp['date_naissance'] ?? '')) !== '' ? e(date('d.m.Y', strtotime($emp['date_naissance']))) : '—' ?></dd></div>
        <div><dt>Numéro AVS</dt><dd><?= e($emp['numero_avs']) ?: '—' ?></dd></div>
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
        <a class="btn" href="?p=fiche_new&employe_id=<?= (int) $emp['id'] ?>"><?= icon('file-plus') ?> Nouvelle fiche</a>
        <?php endif; ?>
    </div>
</div>

<?php if (!$fiches): ?>
    <p class="muted">Aucune fiche de salaire pour cet employé.</p>
<?php else: ?>
<div class="table-scroll">
<table class="list list-wide">
    <thead>
        <tr><th>Mois</th><th class="num">Brut</th><th class="num">Net</th><th>Paiement</th><th class="num">Coût employeur</th><th class="center">Envoyée</th></tr>
    </thead>
    <tbody>
    <?php foreach ($fiches as $f): $apayer = trim((string) $f['date_paiement']) === '' && !fiche_a_venir($f); ?>
        <tr class="row-link" tabindex="0" role="link" data-href="?p=fiche&id=<?= (int) $f['id'] ?>&depuis=employe:<?= (int) $emp['id'] ?>">
            <td><?= e(mois_nom((int) $f['mois'])) ?> <?= (int) $f['annee'] ?></td>
            <td class="num col-brut"><?= chf((float) $f['salaire_brut']) ?></td>
            <td class="num strong <?= $apayer ? 'net-apayer' : (fiche_a_venir($f) ? 'net-avenir' : '') ?>"><?= chf((float) $f['salaire_net']) ?></td>
            <td><?= badge_paiement($f) ?></td>
            <td class="num col-cout"><?= cout_emp_affiche($f) ?></td>
            <td class="center"><?php if (trim((string) ($f['email_envoye_le'] ?? '')) !== ''): ?><span class="mail-sent" title="Envoyée le <?= e(date('d.m.Y', strtotime((string) $f['email_envoye_le']))) ?>"><?= icon('check') ?></span><?php endif; ?></td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
</div>
<?php endif; ?>
</div></div>
