<?php /** @var bool $saved */ /** @var ?string $err */ ?>
<?php require __DIR__ . '/_param_tabs.php'; ?>
<?php if ($saved): ?><p class="ok flash">Coordonnées enregistrées.</p><?php endif; ?>
<?php if ($err): ?><p class="err"><?= e($err) ?></p><?php endif; ?>

<?php if (!peut_ecrire('coeur')): ?>
<p class="err">Vous n'avez pas les droits d'écriture nécessaires pour cette action.</p>
<?php else: ?>
<div class="card card-editable">
    <div class="card-head-row">
        <h2 class="mt-0">Employeur</h2>
        <?= carte_actions_html(['form' => 'employeur-form']) ?>
    </div>

    <?php // En lecture, ce qui figurera sur une fiche de salaire ou une facture
          // — les logos compris, montrés sur le fond qui leur convient. ?>
    <div class="card-disp">
        <table class="kv-table">
            <tr><th>Nom</th><td><?= param('employeur_nom') !== '' ? e(param('employeur_nom')) : '<span class="muted">—</span>' ?></td></tr>
            <tr>
                <th>Logos</th>
                <td>
                    <?php $auMoinsUnLogo = false; ?>
                    <?php foreach ([['clair', 'clair'], ['sombre', 'sombre'], ['mini_clair', 'clair'], ['mini_sombre', 'sombre']] as [$variante, $fond]): ?>
                        <?php if (param_logo($variante) !== ''): $auMoinsUnLogo = true; ?>
                        <span class="logo-preview <?= $fond ?><?= str_starts_with($variante, 'mini_') ? ' mini' : '' ?>"><img src="<?= e(param_logo($variante)) ?>" alt="<?= e($variante) ?>"></span>
                        <?php endif; ?>
                    <?php endforeach; ?>
                    <?php if (!$auMoinsUnLogo): ?><span class="muted">aucun — le nom s'affiche en toutes lettres</span><?php endif; ?>
                </td>
            </tr>
            <tr>
                <th>Adresse</th>
                <td><?php $adresse = array_filter([param('employeur_rue'), param('employeur_npa'), param('employeur_pays')]); ?>
                    <?= $adresse ? e(implode(' · ', $adresse)) : '<span class="muted">—</span>' ?></td>
            </tr>
            <tr>
                <th>Certificat de salaire</th>
                <td><?php $ecs = array_filter([
                        param('employeur_telephone'),
                        param('employeur_heures_hebdo') !== '' ? param('employeur_heures_hebdo') . ' h/sem.' : '',
                        trim(param('employeur_contact_nom') . ' ' . param('employeur_contact_tel')),
                    ]); ?>
                    <?= $ecs ? e(implode(' · ', $ecs)) : '<span class="muted">—</span>' ?></td>
            </tr>
        </table>
    </div>

    <form method="post" action="?p=employeur" enctype="multipart/form-data" id="employeur-form" class="card-edit form" hidden>
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">

        <h3 class="sub no-mt">Nom de l'employeur</h3>
	        <input name="employeur_nom" value="<?= e(param('employeur_nom')) ?>">

        <h3 class="sub">Logos de l'employeur <?= info_tip(
            'Formats acceptés : PNG, JPG, GIF ou WebP (2 Mo max). Laissez vide pour conserver le logo actuel.'
        ) ?></h3>

        <div class="grid2">
            <label>Logo sur fond clair (fiches de salaire, e-mail)
                <?php $lc = param_logo('clair'); ?>
                <?php if ($lc !== ''): ?><span class="logo-preview clair"><img src="<?= e($lc) ?>" alt="<?= e(param('employeur_nom')) ?>"></span><?php endif; ?>
                <input type="file" name="logo_clair" accept="image/png,image/jpeg,image/gif,image/webp">
            </label>
            <label>Logo sur fond sombre (connexion, barre latérale)
                <?php $ls = param_logo('sombre'); ?>
                <?php if ($ls !== ''): ?><span class="logo-preview sombre"><img src="<?= e($ls) ?>" alt="<?= e(param('employeur_nom')) ?>"></span><?php endif; ?>
                <input type="file" name="logo_sombre" accept="image/png,image/jpeg,image/gif,image/webp">
            </label>
        </div>

        <?php // Deux variantes FACULTATIVES pour les endroits où le logo est
              // minuscule — la favicone de l'onglet et la barre latérale : un
              // logo large y devient une ligne illisible. Sans elles, les logos
              // ci-dessus continuent d'y servir (logo_petit_variante()). ?>
        <h3 class="sub">Versions réduites <?= info_tip(
            "Facultatives, pour les endroits où le logo s'affiche tout petit : la favicone de "
            . "l'onglet du navigateur et la barre latérale. Une image carrée (monogramme, symbole "
            . "sans le nom) y reste lisible, là où un logo large se réduit à une ligne. "
            . "Sans elles, les logos ci-dessus servent aussi à ces endroits."
        ) ?></h3>

        <div class="grid2">
            <label>Version réduite sur fond clair
                <?php $lmc = param_logo('mini_clair'); ?>
                <?php if ($lmc !== ''): ?><span class="logo-preview clair mini"><img src="<?= e($lmc) ?>" alt="<?= e(param('employeur_nom')) ?>"></span><?php endif; ?>
                <input type="file" name="logo_mini_clair" accept="image/png,image/jpeg,image/gif,image/webp">
            </label>
            <label>Version réduite sur fond sombre
                <?php $lms = param_logo('mini_sombre'); ?>
                <?php if ($lms !== ''): ?><span class="logo-preview sombre mini"><img src="<?= e($lms) ?>" alt="<?= e(param('employeur_nom')) ?>"></span><?php endif; ?>
                <input type="file" name="logo_mini_sombre" accept="image/png,image/jpeg,image/gif,image/webp">
            </label>
        </div>

        <h3 class="sub">Coordonnées <?= info_tip('Ces coordonnées seront affichées sur les fiches de salaire.') ?></h3>
        <div class="grid3">
            <label>Rue <input name="employeur_rue" value="<?= e(param('employeur_rue')) ?>"></label>
            <label>NPA, localité <input name="employeur_npa" value="<?= e(param('employeur_npa')) ?>"></label>
            <label>Pays <select name="employeur_pays"><?= pays_options_nom(param('employeur_pays')) ?></select></label>
        </div>

        <h3 class="sub">Certificat de salaire (eCS CSI) <?= info_tip(
            "Ces champs ne servent qu'à l'export XML destiné à l'application « eCertificat de salaire CSI »."
        ) ?></h3>
        <div class="grid2">
            <label>Téléphone de l'employeur <input name="employeur_telephone" value="<?= e(param('employeur_telephone')) ?>" placeholder="022 111 22 33"></label>
            <label>Heures hebdomadaires de référence <input name="employeur_heures_hebdo" type="text" inputmode="decimal" value="<?= e(param('employeur_heures_hebdo')) ?>" placeholder="40.00"></label>
        </div>
        <div class="grid2">
            <label>Personne de contact (nom) <input name="employeur_contact_nom" value="<?= e(param('employeur_contact_nom')) ?>"></label>
            <label>Personne de contact (téléphone) <input name="employeur_contact_tel" value="<?= e(param('employeur_contact_tel')) ?>"></label>
        </div>

    </form>
</div>
<?php endif; ?>
