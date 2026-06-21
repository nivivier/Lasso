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
        <a href="?p=employes" class="<?= in_array($cur, ['employes', 'employe', 'employe_voir']) ? 'on' : '' ?>">
            <?= icon('users') ?> Employés
        </a>
        <a href="?p=fiches" class="<?= in_array($cur, ['fiches', 'fiche', 'fiche_new']) ? 'on' : '' ?>">
            <?= icon('file-text') ?> Fiches de salaire
        </a>
        <?php $settingsPages = ['employeur', 'emails', 'taux_horaires', 'unites', 'taux', 'export', 'comptes', 'parametres']; ?>
        <a href="?p=employeur" class="<?= in_array($cur, $settingsPages, true) ? 'on' : '' ?>">
            <?= icon('settings') ?> Paramètres
        </a>
    </nav>
    <div class="side-user">
        <div class="side-user-mail" title="<?= e($u['email']) ?>"><?= e($u['email']) ?></div>
        <a href="?p=compte" class="side-account <?= $cur === 'compte' ? 'on' : '' ?>">Mon compte</a>
        <a href="?p=logout" class="side-logout">Déconnexion</a>
    </div>
    <a class="side-powered" href="https://github.com/nivivier/Lasso" target="_blank" rel="noopener">
        <img src="assets/lasso-blanc.png" alt="" class="side-powered-logo"> Lasso
    </a>
</aside>
<main class="content">
    <?php require $contentView; ?>
</main>
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
