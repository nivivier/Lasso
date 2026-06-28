<?php /** @var string $pageTitle, $contentView */ $u = current_user(); $cur = $_GET['p'] ?? '';
$nomEmployeur = param('employeur_nom') ?: 'Fiches de salaire';
$logoClair = param_logo('clair'); $logoSombre = param_logo('sombre'); ?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($pageTitle) ?> — <?= e($nomEmployeur) ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap">
    <link rel="stylesheet" href="assets/app.css?v=<?= @filemtime(__DIR__ . '/../assets/app.css') ?: '1' ?>">
</head>
<body class="<?= $u ? 'has-sidebar' : 'auth-bg' ?>">
<?php if ($u): ?>
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
            <?php if ($logoSombre !== ''): ?><img src="<?= e($logoSombre) ?>" alt="<?= e($nomEmployeur) ?>" class="side-logo"><?php else: ?><span class="side-name"><?= e($nomEmployeur) ?></span><?php endif; ?>
            <span class="side-sub">Gestion des salaires</span>
        </div>
        <button type="button" class="side-close" id="side-close" aria-label="Fermer"><?= icon('x') ?></button>
    </div>
    <nav class="side-nav">
        <a href="?p=resumes" class="<?= $cur === 'resumes' ? 'on' : '' ?>">
            <?= icon('bar-chart') ?> Tableau de bord
        </a>
        <span class="side-nav-sep">Salaires</span>
        <a href="?p=employes" class="<?= in_array($cur, ['employes', 'employe', 'employe_voir']) ? 'on' : '' ?>">
            <?= icon('users') ?> Employés
        </a>
        <?php $nbFiches = nb_fiches_a_payer(); ?>
        <a href="?p=fiches" class="<?= in_array($cur, ['fiches', 'fiche', 'fiche_new']) ? 'on' : '' ?>">
            <?= icon('file-text') ?> Fiches de salaire
            <?php if ($nbFiches > 0): ?><span class="nav-badge"><?= $nbFiches ?></span><?php endif; ?>
        </a>
        <span class="side-nav-sep">Comptabilité</span>
        <?php $ecrituresPages = ['compta', 'compta_ecritures', 'compta_lettrage', 'compta_import', 'compta_regles']; ?>
        <?php $nbEcr = nb_ecritures_a_lettrer(); ?>
        <a href="?p=compta_ecritures" class="<?= in_array($cur, $ecrituresPages, true) ? 'on' : '' ?>">
            <?= icon('banknote') ?> Écritures
            <?php if ($nbEcr > 0): ?><span class="nav-badge"><?= $nbEcr ?></span><?php endif; ?>
        </a>
        <?php $bilanPages = ['compta_bilan', 'compta_plan', 'compta_comptes']; ?>
        <a href="?p=compta_bilan" class="<?= in_array($cur, $bilanPages, true) ? 'on' : '' ?>">
            <?= icon('book-open') ?> Comptes annuels
        </a>
        <?php $analysePages = ['compta_analyse', 'compta_analyse_axe', 'compta_axes']; ?>
        <a href="?p=compta_analyse" class="<?= in_array($cur, $analysePages, true) ? 'on' : '' ?>">
            <?= icon('layers') ?> Analyse
        </a>
        <span class="side-nav-sep"></span>
        <?php $settingsPages = ['employeur', 'emails', 'taux_horaires', 'unites', 'taux', 'export', 'import_fiches', 'comptes', 'maj', 'parametres']; ?>
        <a href="?p=employeur" class="<?= in_array($cur, $settingsPages, true) ? 'on' : '' ?>">
            <?= icon('settings') ?> Paramètres
        </a>
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
        <button class="side-avatar" id="side-avatar-btn" aria-haspopup="true" aria-expanded="false">
            <?= e($initiales) ?>
        </button>
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
        <img src="assets/lasso-blanc.png" alt="" class="side-powered-logo"> Lasso <span class="side-version">v<?= e(maj_version_locale()) ?></span>
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

    // Messages flottants : disparition automatique après 3 s
    document.querySelectorAll('.flash').forEach(el => {
        setTimeout(() => { el.classList.add('flash-out'); setTimeout(() => el.remove(), 400); }, 3000);
    });

    // Lignes cliquables (souris + clavier). Un clic sur un lien/bouton dans la
    // ligne garde son comportement propre.
    function go(el) { const u = el.getAttribute('data-href'); if (u) location.href = u; }
    document.querySelectorAll('tr.row-link[data-href]').forEach(row => {
        row.addEventListener('click', e => { if (!e.target.closest('a,button')) go(row); });
        row.addEventListener('keydown', e => {
            if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); go(row); }
        });
    });

    // Clic sur le texte résumé → bascule résumé ↔ texte brut complet (toutes pages).
    document.addEventListener('click', e => {
        const td = e.target.closest('.compta-lettrage .texte-cell');
        if (!td) return;
        const expanded = td.classList.toggle('expanded');
        td.textContent = expanded ? td.title : td.dataset.summary;
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
<main class="auth-wrap">
    <?php if ($logoClair !== ''): ?><img src="<?= e($logoClair) ?>" alt="<?= e($nomEmployeur) ?>" class="auth-logo"><?php else: ?><div class="auth-name"><?= e($nomEmployeur) ?></div><?php endif; ?>
    <?php require $contentView; ?>
    <p class="auth-foot">Gestion des salaires<?= $nomEmployeur !== 'Fiches de salaire' ? ' · ' . e($nomEmployeur) : '' ?></p>
</main>
<?php endif; ?>
</body>
</html>
