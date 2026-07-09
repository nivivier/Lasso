<?php /** @var bool $saved */ /** @var ?string $err */ ?>
<?php require __DIR__ . '/_param_tabs.php'; ?>
<?php if ($saved): ?><p class="ok flash">Coordonnées enregistrées.</p><?php endif; ?>
<?php if ($err): ?><p class="err"><?= e($err) ?></p><?php endif; ?>

<div class="card form">
    <form method="post" action="?p=employeur" enctype="multipart/form-data">
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">

        <h3 class="sub no-mt">Nom de l'employeur</h3>
	        <input name="employeur_nom" value="<?= e(param('employeur_nom')) ?>">

        <h3 class="sub">Logos de l'employeur</h3>

        <p class="muted small">Formats acceptés : PNG, JPG, GIF ou WebP (2 Mo max). Laissez vide pour conserver le logo actuel.</p>
        <div class="grid2">
            <label>Logo sur fond clair (fiches de salaire, e-mail)
                <?php $lc = param_logo('clair'); ?>
                <?php if ($lc !== ''): ?><span class="logo-preview clair"><img src="<?= e($lc) ?>" alt=""></span><?php endif; ?>
                <input type="file" name="logo_clair" accept="image/png,image/jpeg,image/gif,image/webp">
            </label>
            <label>Logo sur fond sombre (connexion, barre latérale)
                <?php $ls = param_logo('sombre'); ?>
                <?php if ($ls !== ''): ?><span class="logo-preview sombre"><img src="<?= e($ls) ?>" alt=""></span><?php endif; ?>
                <input type="file" name="logo_sombre" accept="image/png,image/jpeg,image/gif,image/webp">
            </label>
        </div>

        <h3 class="sub">Couleur principale</h3>
        <p class="muted small">Utilisée pour les boutons, liens et accents dans toute l'application ;
        les autres teintes (survols, fonds, en-têtes) sont calculées automatiquement à partir de celle-ci.</p>
        <div class="color-field">
            <input type="color" name="employeur_couleur_principale" id="couleur-principale"
                   value="<?= e(param('employeur_couleur_principale', '#6d4ade')) ?>">
            <code id="couleur-principale-hex"><?= e(param('employeur_couleur_principale', '#6d4ade')) ?></code>
        </div>
        <script>
            document.getElementById('couleur-principale').addEventListener('input', function () {
                document.getElementById('couleur-principale-hex').textContent = this.value;
            });
        </script>

        <h3 class="sub">Coordonnées</h3>
        <p class="muted small">Ces coordonnées seront affichées sur les fiches de salaire</p>
        <div class="grid3">
            <label>Rue <input name="employeur_rue" value="<?= e(param('employeur_rue')) ?>"></label>
            <label>NPA, localité <input name="employeur_npa" value="<?= e(param('employeur_npa')) ?>"></label>
            <label>Pays <input name="employeur_pays" value="<?= e(param('employeur_pays')) ?>"></label>
        </div>

        <h3 class="sub">Certificat de salaire (eCS CSI)</h3>
        <p class="muted small">Ces champs ne servent qu'à l'export XML destiné à l'application « eCertificat de salaire CSI ».</p>
        <div class="grid2">
            <label>Téléphone de l'employeur <input name="employeur_telephone" value="<?= e(param('employeur_telephone')) ?>" placeholder="022 111 22 33"></label>
            <label>Heures hebdomadaires de référence <input name="employeur_heures_hebdo" type="text" inputmode="decimal" value="<?= e(param('employeur_heures_hebdo')) ?>" placeholder="40.00"></label>
        </div>
        <div class="grid2">
            <label>Personne de contact (nom) <input name="employeur_contact_nom" value="<?= e(param('employeur_contact_nom')) ?>"></label>
            <label>Personne de contact (téléphone) <input name="employeur_contact_tel" value="<?= e(param('employeur_contact_tel')) ?>"></label>
        </div>

        <div class="form-actions">
            <button type="submit"><?= icon('save') ?> Enregistrer</button>
        </div>
    </form>
</div>
