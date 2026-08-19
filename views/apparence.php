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
            . "Formats acceptés : PNG, JPG, GIF ou WebP (2 Mo max). Sans image personnalisée, "
            . "un fond calculé à partir des couleurs ci-dessus est utilisé."
        ) ?></h3>
        <?php if (param('employeur_fond', '') !== ''): ?>
        <span class="fond-preview"><img src="<?= e(param_fond()) ?>" alt="Fond actuel"></span>
        <?php endif; ?>
        <input type="file" name="fond" accept="image/png,image/jpeg,image/gif,image/webp">
        <?php if (param('employeur_fond', '') !== ''): ?>
        <label class="check mt-16">
            <input type="checkbox" name="employeur_fond_clair" value="1" <?= param_fond_clair() ? 'checked' : '' ?>>
            Fond clair <?= info_tip("Adoucit et éclaircit l'image pour une meilleure lisibilité du contenu par-dessus.") ?>
        </label>
        <label class="check">
            <input type="checkbox" name="employeur_fond_floute" value="1" <?= param_fond_floute() ? 'checked' : '' ?>>
            Fond flouté <?= info_tip("Applique un flou à l'image. Combinable avec « Fond clair ».") ?>
        </label>
        <div class="mt-16">
            <?php // formaction : ce bouton soumet le même formulaire vers une route dédiée
                  // (route_apparence_fond_supprimer()), sans toucher couleurs/effets — un
                  // <form> imbriqué serait invalide en HTML. ?>
            <button type="submit" formaction="?p=apparence_fond_supprimer" formnovalidate class="btn ghost btn-sm"
                    data-confirm="Supprimer l'image de fond personnalisée et revenir au fond par défaut ?">
                <?= icon('trash') ?> Supprimer l'image de fond
            </button>
        </div>
        <?php else: ?>
        <p class="muted small mt-8">Fond actuel : calculé automatiquement (aucune image personnalisée).</p>
        <?php endif; ?>

        <div class="form-actions">
            <button type="submit"><?= icon('save') ?> Enregistrer</button>
        </div>
    </form>
</div>
<script nonce="<?= e(csp_nonce()) ?>">
    document.querySelectorAll('.color-field input[type=color]').forEach(function (input) {
        var hex = document.getElementById(input.id + '-hex');
        if (hex) input.addEventListener('input', function () { hex.textContent = this.value; });
    });
</script>
