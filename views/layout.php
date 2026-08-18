<?php /** @var string $pageTitle, $contentView */ $u = current_user(); $cur = $_GET['p'] ?? '';
$nomEmployeur = param('employeur_nom') ?: 'Fiches de salaire';
$logoClair = param_logo('clair'); $logoSombre = param_logo('sombre');
// Fond calculé (views/_wave_decor.php) tant qu'aucune image personnalisée
// n'est configurée (?p=apparence) — sinon on garde l'image uploadée telle
// quelle (body.has-sidebar::before, voir couleurs_css_vars()).
$fondPersonnalise = param('employeur_fond', '') !== '';
// Calculés ici (avant <head>, pas seulement pour la boucle du rail plus bas)
// pour pouvoir injecter module_couleur_css_vars($navActif) dans <head>.
$navGroupes = $u ? nav_groupes() : [];
$navActif   = $u ? nav_groupe_actif($navGroupes, $cur, (string) ($_GET['depuis'] ?? '')) : null;
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($pageTitle) ?> — <?= e($nomEmployeur) ?></title>
    <?php // Inter est servie depuis le dépôt (assets/fonts/, voir le @font-face
          // en tête de app.css) : plus aucune requête vers fonts.googleapis.com.
          // Préchargée car elle est découverte tardivement (référencée depuis la
          // feuille de style, donc après son téléchargement et son analyse). ?>
    <?php // URL sans « ?v= », volontairement : elle doit correspondre au
          // caractère près à celle du @font-face, sinon le navigateur
          // télécharge le fichier deux fois et le préchargement est perdu. La
          // police est immuable — si elle change un jour, changer son nom. ?>
    <link rel="preload" href="assets/fonts/inter-latin-var.woff2" as="font" type="font/woff2" crossorigin>
    <link rel="stylesheet" href="assets/app.css?v=<?= @filemtime(__DIR__ . '/../assets/app.css') ?: '1' ?>">
    <script src="assets/app.js?v=<?= @filemtime(__DIR__ . '/../assets/app.js') ?: '1' ?>"></script>
    <?= couleurs_css_vars() ?>
    <?= module_couleur_css_vars($navActif) ?>
</head>
<body class="<?= $u ? 'has-sidebar' : 'auth-bg' ?>">
<?php if ($u): ?>
<?php if (!$fondPersonnalise) { require __DIR__ . '/_wave_decor.php'; } ?>
<header class="mobile-bar">
    <?php if ($logoSombre !== ''): ?><img src="<?= e($logoSombre) ?>" alt="<?= e($nomEmployeur) ?>" class="mbar-logo"><?php else: ?><span class="mbar-name"><?= e($nomEmployeur) ?></span><?php endif; ?>
    <button type="button" class="burger" id="burger" aria-label="Menu" aria-expanded="false">
        <?= icon('menu') ?>
    </button>
</header>
<div class="scrim" id="scrim"></div>
<aside class="sidebar" id="sidebar">
    <div class="side-brand">
        <div class="side-brand-txt">
            <?php if ($logoClair !== ''): ?><img src="<?= e(param_logo_data_uri('clair')) ?>" alt="<?= e($nomEmployeur) ?>" class="side-logo"><?php else: ?><span class="side-name"><?= e($nomEmployeur) ?></span><?php endif; ?>
            <span class="side-sub">Gestion des salaires</span>
        </div>
        <button type="button" class="side-close" id="side-close" aria-label="Fermer"><?= icon('x') ?></button>
    </div>
    <nav class="side-nav">
        <?php // --rail-accent explicite : sans lui, .rail-btn .ico retombe sur
              // --muted et l'icône reste grise au repos, alors que celles des
              // modules portent toujours leur couleur. C'est la couleur
              // principale de l'application, via --primary-base et NON
              // --primary : cette dernière est réécrite par
              // module_couleur_css_vars() à la couleur du module courant, ce
              // qui ferait changer de teinte l'icône du tableau de bord au fil
              // de la navigation — alors qu'elle doit rester un repère
              // constant, exactement comme les icônes de module. ?>
        <a href="?p=resumes" class="rail-btn <?= $cur === 'resumes' ? 'on' : '' ?>" title="Tableau de bord" style="--rail-accent: var(--primary-base)">
            <?= icon('circle-gauge') ?>
            <span class="rail-label">Tableau de bord</span>
        </a>
        <?php foreach ($navGroupes as $navCle => $navG): ?>
        <?php $navBadge = array_sum(array_column($navG[2], 2)); ?>
        <a href="?p=<?= array_key_first($navG[2]) ?>" class="rail-btn <?= $navActif === $navCle ? 'on' : '' ?>" title="<?= e($navG[0]) ?>" style="--rail-accent: <?= e(MODULE_COULEURS[$navCle] ?? '') ?>">
            <?= icon($navG[1]) ?>
            <span class="rail-label"><?= e($navG[0]) ?></span>
            <?php if ($navBadge > 0): ?><span class="nav-badge"><?= $navBadge ?></span><?php endif; ?>
        </a>
        <?php endforeach; ?>
    </nav>
    <?php
    $prenom = trim((string)($u['prenom'] ?? ''));
    $nom    = trim((string)($u['nom'] ?? ''));
    if ($prenom !== '' && $nom !== '') {
        $initiales  = mb_strtoupper(mb_substr($prenom, 0, 1) . mb_substr($nom, 0, 1), 'UTF-8');
        $nomComplet = $prenom . ' ' . $nom;
    } elseif ($prenom !== '' || $nom !== '') {
        $n = $prenom !== '' ? $prenom : $nom;
        $initiales  = mb_strtoupper(mb_substr($n, 0, 2), 'UTF-8');
        $nomComplet = $n;
    } else {
        $initiales  = mb_strtoupper(mb_substr($u['email'], 0, 2), 'UTF-8');
        $nomComplet = $u['email'];
    }
    ?>
    <div class="side-avatar-wrap" id="side-avatar-wrap">
        <?php // Paramètres tient compagnie à la pastille du compte plutôt que de
              // figurer dans le rail : ce n'est pas un module, et l'y afficher
              // comme les autres lui donnait le même poids visuel qu'un domaine
              // métier. Les deux sont des réglages, pas du contenu. ?>
        <div class="side-bottom">
            <button class="side-avatar" id="side-avatar-btn" aria-haspopup="true" aria-expanded="false">
                <?= e($initiales) ?>
            </button>
            <?php if (peut_lire('coeur')): ?>
            <?php $settingsPages = ['employeur', 'emails', 'taux_horaires', 'unites', 'taux', 'export', 'import_fiches', 'import_structures', 'comptes', 'parametres_modules', 'maj', 'parametres', 'parametres_evenements', 'parametres_structures']; ?>
            <a href="?p=maj" class="side-cog <?= in_array($cur, $settingsPages, true) ? 'on' : '' ?>" title="Paramètres" aria-label="Paramètres">
                <?= icon('settings') ?>
            </a>
            <?php endif; ?>
        </div>
        <div class="side-avatar-menu" id="side-avatar-menu" hidden>
            <div class="side-avatar-id">
                <strong><?= e($nomComplet) ?></strong>
                <span><?= e($u['email']) ?></span>
            </div>
            <a href="?p=compte" class="<?= $cur === 'compte' ? 'on' : '' ?>">Mon compte</a>
            <a href="?p=logout">Déconnexion</a>
        </div>
    </div>
    <a class="side-powered" href="https://github.com/nivivier/Lasso" target="_blank" rel="noopener">
        <img src="<?= e(asset_data_uri_mini('assets/lasso.png', 32)) ?>" alt="" class="side-powered-logo"> Lasso <span class="side-version">v<?= e(maj_version_locale()) ?></span>
    </a>
</aside>
<main class="content">
    <?php require $contentView; ?>
</main>
<div id="preview-modal" hidden aria-modal="true" role="dialog" aria-label="Aperçu">
    <div id="preview-modal-inner">
        <button id="preview-modal-close" aria-label="Fermer l'aperçu"><?= icon('x') ?></button>
        <iframe id="preview-modal-frame" src="" title="Aperçu"></iframe>
    </div>
</div>
<script>
(function () {
    const body = document.body, burger = document.getElementById('burger'),
          close = document.getElementById('side-close'), scrim = document.getElementById('scrim');
    function toggle(open) {
        body.classList.toggle('nav-open', open);
        burger.setAttribute('aria-expanded', open ? 'true' : 'false');
    }
    burger.addEventListener('click', () => toggle(!body.classList.contains('nav-open')));
    close.addEventListener('click', () => toggle(false));
    scrim.addEventListener('click', () => toggle(false));

    // Pastille utilisateur : ouvre/ferme le menu au clic, ferme si clic dehors.
    const avatarBtn  = document.getElementById('side-avatar-btn');
    const avatarMenu = document.getElementById('side-avatar-menu');
    avatarBtn.addEventListener('click', (e) => {
        e.stopPropagation();
        const open = avatarMenu.hasAttribute('hidden');
        avatarMenu.toggleAttribute('hidden', !open);
        avatarBtn.setAttribute('aria-expanded', open ? 'true' : 'false');
    });
    document.addEventListener('click', () => {
        avatarMenu.setAttribute('hidden', '');
        avatarBtn.setAttribute('aria-expanded', 'false');
    });

    // Infobulles « i » (.info-tip) : tap pour basculer — indispensable sur
    // mobile où :hover ne s'applique pas. Une seule ouverte à la fois, fermeture
    // au clic dehors ou à Echap.
    document.addEventListener('click', e => {
        const tip = e.target.closest('.info-tip');
        document.querySelectorAll('.info-tip.open').forEach(t => { if (t !== tip) t.classList.remove('open'); });
        if (tip) {
            // Empêche le <label> englobant de transférer le clic à son champ
            // (sinon ce clic « fantôme » referme aussitôt la bulle qu'on ouvre).
            e.preventDefault();
            e.stopPropagation();
            tip.classList.toggle('open');
        }
    });
    document.addEventListener('keydown', e => {
        if (e.key === 'Escape') document.querySelectorAll('.info-tip.open').forEach(t => t.classList.remove('open'));
    });

    // Messages flottants : disparition automatique après 3 s
    document.querySelectorAll('.flash').forEach(el => {
        setTimeout(() => { el.classList.add('flash-out'); setTimeout(() => el.remove(), 400); }, 3000);
    });

    // Lignes cliquables (souris + clavier). Un clic sur un lien/bouton/case à
    // cocher dans la ligne garde son comportement propre — form inclus (ex.
    // formulaire d'ajout d'étiquette par ligne, ?p=structures, ou les
    // formulaires déjà présents dans les lignes de ?p=spectacles) : sans ça,
    // un clic dans un espace du formulaire hors bouton/champ (padding entre
    // deux champs, etc.) déclenchait quand même la navigation de la ligne.
    // .cat-search-list répétée à part (déjà couverte par closest('form') vu
    // qu'elle y est nichée aujourd'hui) : au cas où une future liste de
    // suggestions apparaisse un jour hors d'un <form> dans une ligne.
    function go(el) { const u = el.getAttribute('data-href'); if (u) location.href = u; }
    document.querySelectorAll('tr.row-link[data-href]').forEach(row => {
        row.addEventListener('click', e => { if (!e.target.closest('a,button,input,form,.cat-search-list')) go(row); });
        row.addEventListener('keydown', e => {
            if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); go(row); }
        });
    });

    // Clic sur le texte résumé → bascule résumé ↔ texte brut complet (toutes pages).
    document.addEventListener('click', e => {
        const td = e.target.closest('.compta-lettrage .texte-cell');
        if (!td || e.target.closest('a,button')) return;
        const txt = td.querySelector('.texte-cell-txt');
        if (!txt) return;
        const expanded = td.classList.toggle('expanded');
        txt.textContent = expanded ? td.title : txt.dataset.summary;
    });

    // Modal plein écran pour les aperçus d'impression (liens [data-preview]).
    const previewModal = document.getElementById('preview-modal');
    const previewFrame = document.getElementById('preview-modal-frame');
    const previewClose = document.getElementById('preview-modal-close');
    function openPreview(url) {
        previewFrame.src = url;
        previewModal.removeAttribute('hidden');
        document.body.style.overflow = 'hidden';
    }
    function closePreview() {
        previewModal.setAttribute('hidden', '');
        previewFrame.src = '';
        document.body.style.overflow = '';
    }
    document.addEventListener('click', e => {
        const a = e.target.closest('a[data-preview]');
        if (!a || e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey) return;
        e.preventDefault();
        openPreview(a.href);
    });
    previewClose.addEventListener('click', closePreview);
    previewModal.addEventListener('click', e => { if (e.target === previewModal) closePreview(); });
    document.addEventListener('keydown', e => {
        if (e.key === 'Escape' && !previewModal.hasAttribute('hidden')) closePreview();
    });
    // Intercepte "Fermer" et Escape dans l'iframe (même origine → accès DOM autorisé).
    previewFrame.addEventListener('load', () => {
        try {
            const doc = previewFrame.contentDocument;
            doc.querySelectorAll('.print-toolbar a').forEach(a => {
                a.addEventListener('click', ev => { ev.preventDefault(); closePreview(); });
            });
            doc.addEventListener('keydown', ev => {
                if (ev.key === 'Escape') { ev.stopPropagation(); closePreview(); }
            }, true);
        } catch(err) {}
    });
})();
</script>
<?php else: ?>
<?php require __DIR__ . '/_wave_decor.php'; ?>
<main class="auth-wrap">
    <?php if ($logoClair !== ''): ?><img src="<?= e($logoClair) ?>" alt="<?= e($nomEmployeur) ?>" class="auth-logo"><?php else: ?><div class="auth-name"><?= e($nomEmployeur) ?></div><?php endif; ?>
    <?php require $contentView; ?>
    <a class="side-powered auth-powered" href="https://github.com/nivivier/Lasso" target="_blank" rel="noopener">
        <img src="<?= e(asset_data_uri_mini('assets/lasso.png', 32)) ?>" alt="" class="side-powered-logo"> Lasso <span class="side-version">v<?= e(maj_version_locale()) ?></span>
    </a>
</main>
<?php endif; ?>
</body>
</html>
