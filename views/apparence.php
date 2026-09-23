<?php /** @var bool $saved */ /** @var ?string $err */ ?>
<?php // Le choix ENREGISTRÉ, que montre le sélecteur — et non param_fond_decor(),
      // qui répond ce qui s'affiche vraiment (avec son repli si l'image manque). ?>
<?php $fondChoisi = fond_decor_valide((string) param('employeur_fond_decor', 'maillage')); ?>
<?php require __DIR__ . '/_param_tabs.php'; ?>
<?php if ($saved): ?><p class="ok flash">Apparence enregistrée.</p><?php endif; ?>
<?php if ($err): ?><p class="err"><?= e($err) ?></p><?php endif; ?>

<div class="card form">
    <form method="post" action="?p=apparence" enctype="multipart/form-data">
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">

        <?php // Deux colonnes : à gauche ce qui touche aux couleurs de
              // l'interface, à droite le fond et son aperçu — deux sujets qu'on
              // règle en se regardant l'un l'autre. Une seule colonne dès que
              // la fenêtre se resserre (.grid2-optional). ?>
        <div class="grid2-optional">
        <div>

        <h3 class="sub no-mt">Thème <?= info_tip(
            "« Automatique » suit le réglage clair/sombre de votre système d'exploitation. "
            . 'Ce choix vaut pour toute l\'installation, comme les couleurs ci-dessous.'
        ) ?></h3>
        <?= icon_picker('employeur_theme', [
            'auto'   => ['icone' => 'monitor', 'label' => 'Automatique (système)'],
            'clair'  => ['icone' => 'sun',     'label' => 'Clair'],
            'sombre' => ['icone' => 'moon',    'label' => 'Sombre'],
        ], param_theme(), 'Thème de l\'interface') ?>

        <h3 class="sub">Couleur principale <?= info_tip(
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

        </div>
        <div>

        <h3 class="sub no-mt">Fond <?= info_tip(
            "Les quatre décors sont calculés à partir des couleurs ci-dessus : ils suivent le "
            . "thème, ne pèsent rien et s'affichent aussi sur les écrans hors session (connexion, "
            . "mot de passe oublié). L'image personnalisée, elle, ne s'affiche que derrière "
            . "l'application une fois connecté."
        ) ?></h3>
        <div class="fond-rangee">
            <?= icon_picker('employeur_fond_decor', [
                'maillage' => ['icone' => 'sparkles',     'label' => 'Maillage — taches de couleur floutées (défaut)'],
                'vagues'   => ['icone' => 'waves',        'label' => 'Vagues'],
                'grille'   => ['icone' => 'grid-3x3',     'label' => 'Grille'],
                'courbes'  => ['icone' => 'chart-spline', 'label' => 'Courbes de niveau'],
                'image'    => ['icone' => 'image',        'label' => 'Image personnalisée'],
            ], $fondChoisi, 'Fond de l\'application') ?>
        </div>

        <?php // Les commandes de l'image, entre le sélecteur et l'aperçu : on
              // désigne un fichier, l'aperçu juste en dessous le montre aussitôt.
              // Un <label> porte le bouton et cache le champ — le champ natif et
              // son « Aucun fichier choisi » tiennent mal dans une colonne, et
              // cliquer le label ouvre le même sélecteur de fichiers. ?>
        <div class="fond-rangee mt-10" id="fond-actions"<?= $fondChoisi === 'image' ? '' : ' hidden' ?>>
            <label class="btn ghost fond-parcourir">
                <?= icon('upload') ?> Parcourir
                <input type="file" name="fond" id="fond-fichier" accept="image/png,image/jpeg,image/gif,image/webp" hidden>
            </label>
            <?php if (param('employeur_fond', '') !== ''): ?>
            <?php // formaction : ce bouton soumet le même formulaire vers une route
                  // dédiée (route_apparence_fond_supprimer()), sans toucher aux
                  // couleurs ni aux effets — un <form> imbriqué serait invalide. ?>
            <button type="submit" formaction="?p=apparence_fond_supprimer" formnovalidate class="btn danger"
                    data-confirm="Supprimer l'image de fond personnalisée et revenir au fond par défaut ?">
                <?= icon('trash') ?> Supprimer l'image
            </button>
            <?php endif; ?>
            <span class="muted small" id="fond-fichier-nom" hidden></span>
        </div>
        <?php // Un aperçu par décor, un seul visible : le même balisage que le
              // vrai fond (views/_fond_decor.php), à l'échelle d'une vignette.
              // Les cinq sont rendus côté serveur et le script ne fait que les
              // montrer — rien à reconstruire au clic, et l'aperçu est juste
              // avant même que JavaScript ait tourné. ?>
        <div class="mt-16">
            <?php foreach (['maillage', 'vagues', 'grille', 'courbes'] as $fdApercu): ?>
            <div class="fond-apercu" data-fond-apercu="<?= e($fdApercu) ?>"<?= $fondChoisi === $fdApercu ? '' : ' hidden' ?>>
                <?php $fondDecor = $fdApercu; $fondIdPrefixe = 'ap-' . $fdApercu . '-'; require __DIR__ . '/_fond_decor.php'; ?>
            </div>
            <?php endforeach; ?>
            <?php // L'image et le message « aucune image » coexistent, l'un
                  // caché : choisir un fichier ne fait que basculer les deux et
                  // remplir le src (lecture locale en data URI — blob: n'est pas
                  // autorisé par la CSP). Rien ne part au serveur avant
                  // « Enregistrer ». ?>
            <div class="fond-apercu" data-fond-apercu="image"<?= $fondChoisi === 'image' ? '' : ' hidden' ?>>
                <?php $fondImage = param('employeur_fond', '') !== ''; ?>
                <img id="fond-apercu-image" src="<?= $fondImage ? e(param_fond()) : '' ?>" alt="Aperçu du fond personnalisé"
                     class="<?= param_fond_clair() ? 'f-clair ' : '' ?><?= param_fond_floute() ? 'f-floute' : '' ?>"<?= $fondImage ? '' : ' hidden' ?>>
                <div id="fond-apercu-vide" class="fond-apercu-vide"<?= $fondImage ? ' hidden' : '' ?>>Aucune image envoyée</div>
            </div>
            <p class="muted small mt-8">Fond actuel : <?= e(FONDS_DECOR[param_fond_decor()]) ?>.</p>
        </div>

        <?php // Tout ce qui concerne l'image ne s'affiche que si c'est elle qui
              // est choisie : le reste du temps, ces réglages ne servent à rien
              // et allongent la page. État initial posé par le serveur (pas de
              // clignotement au chargement), puis suivi par le script en pied
              // de page, qui écoute le sélecteur. ?>
        <div id="fond-image-bloc"<?= $fondChoisi === 'image' ? '' : ' hidden' ?>>
        <?php if (param('employeur_fond', '') !== ''): ?>
        <label class="check mt-16">
            <input type="checkbox" name="employeur_fond_clair" value="1" <?= param_fond_clair() ? 'checked' : '' ?>>
            Fond clair <?= info_tip("Adoucit et éclaircit l'image pour une meilleure lisibilité du contenu par-dessus.") ?>
        </label>
        <label class="check">
            <input type="checkbox" name="employeur_fond_floute" value="1" <?= param_fond_floute() ? 'checked' : '' ?>>
            Fond flouté <?= info_tip("Applique un flou à l'image. Combinable avec « Fond clair ».") ?>
        </label>
        <?php endif; ?>
        </div>

        </div>
        </div>

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
    // Fond : le sélecteur montre la vignette correspondante et les réglages
    // d'image, sans recharger. Un seul chemin, appelé aussi quand on désigne un
    // fichier — choisir une image, c'est choisir « Image personnalisée ».
    var blocImage = document.getElementById('fond-image-bloc');
    var champFichier = document.getElementById('fond-fichier');
    function montrerFond(choix) {
        if (blocImage) blocImage.hidden = choix !== 'image';
        var actions = document.getElementById('fond-actions');
        if (actions) actions.hidden = choix !== 'image';
        document.querySelectorAll('[data-fond-apercu]').forEach(function (vignette) {
            vignette.hidden = vignette.dataset.fondApercu !== choix;
        });
    }
    document.querySelectorAll('input[name="employeur_fond_decor"]').forEach(function (radio) {
        radio.addEventListener('change', function () { montrerFond(this.value); });
    });
    // Les deux effets se voient tout de suite sur l'aperçu : ce sont les mêmes
    // filtres que le fond réel (couleurs_css_vars()), le flou en proportion de
    // la vignette — 10px sur un écran de 1280 en font 2,5 sur 300.
    var apercuImage = document.getElementById('fond-apercu-image');
    [['employeur_fond_clair', 'f-clair'], ['employeur_fond_floute', 'f-floute']].forEach(function (paire) {
        var champ = document.querySelector('input[name="' + paire[0] + '"]');
        if (!champ || !apercuImage) return;
        champ.addEventListener('change', function () {
            apercuImage.classList.toggle(paire[1], this.checked);
        });
    });
    if (champFichier) {
        champFichier.addEventListener('change', function () {
            var fichier = this.files && this.files[0];
            if (!fichier) return;
            var lecteur = new FileReader();
            lecteur.onload = function () {
                var img = document.getElementById('fond-apercu-image');
                var vide = document.getElementById('fond-apercu-vide');
                if (img) { img.src = lecteur.result; img.hidden = false; }
                if (vide) vide.hidden = true;
            };
            lecteur.readAsDataURL(fichier);
            var nom = document.getElementById('fond-fichier-nom');
            if (nom) { nom.textContent = fichier.name; nom.hidden = false; }
            var radioImage = document.querySelector('input[name="employeur_fond_decor"][value="image"]');
            if (radioImage) { radioImage.checked = true; }
            montrerFond('image');
        });
    }
</script>
