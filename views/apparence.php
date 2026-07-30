<?php /** @var bool $saved */ /** @var ?string $err */ ?>
<?php require __DIR__ . '/_param_tabs.php'; ?>
<?php if ($saved): ?><p class="ok flash">Apparence enregistrée.</p><?php endif; ?>
<?php if ($err): ?><p class="err"><?= e($err) ?></p><?php endif; ?>

<div class="card form">
    <form method="post" action="?p=apparence" enctype="multipart/form-data">
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">

        <h3 class="sub no-mt">Couleur principale <?= info_tip(
            "Utilisée pour les accents dans toute l'application (barre latérale, survols, fonds, "
            . 'en-têtes) ; les autres teintes sont calculées automatiquement à partir de celle-ci.'
        ) ?></h3>
        <div class="color-field">
            <input type="color" name="employeur_couleur_principale" id="couleur-principale"
                   value="<?= e(param('employeur_couleur_principale', '#6d4ade')) ?>">
            <code id="couleur-principale-hex"><?= e(param('employeur_couleur_principale', '#6d4ade')) ?></code>
        </div>

        <h3 class="sub">Couleur de mise en évidence <?= info_tip(
            'Remplace la couleur principale à certains endroits : boutons principaux, sommes de '
            . 'salaire brut, liens et tags.'
        ) ?></h3>
        <div class="color-field">
            <input type="color" name="employeur_couleur_evidence" id="couleur-evidence"
                   value="<?= e(param('employeur_couleur_evidence', '#2563eb')) ?>">
            <code id="couleur-evidence-hex"><?= e(param('employeur_couleur_evidence', '#2563eb')) ?></code>
        </div>

        <h3 class="sub">Image de fond <?= info_tip(
            "Affichée en arrière-plan de l'application (hors page de connexion). "
            . 'Formats acceptés : PNG, JPG, GIF ou WebP (2 Mo max). Laissez vide pour conserver le fond actuel.'
        ) ?></h3>
        <span class="fond-preview"><img src="<?= e(param_fond()) ?>" alt="Fond actuel"></span>
        <input type="file" name="fond" accept="image/png,image/jpeg,image/gif,image/webp">

        <div class="form-actions">
            <button type="submit"><?= icon('save') ?> Enregistrer</button>
        </div>
    </form>
</div>
<script>
    document.querySelectorAll('.color-field input[type=color]').forEach(function (input) {
        var hex = document.getElementById(input.id + '-hex');
        if (hex) input.addEventListener('input', function () { hex.textContent = this.value; });
    });
</script>
