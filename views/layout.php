<?php /** @var string $pageTitle, $contentView */ $u = current_user(); $cur = $_GET['p'] ?? '';
$nomEmployeur = param('employeur_nom') ?: 'Fiches de salaire';
$logoClair = param_logo('clair'); $logoSombre = param_logo('sombre');
// Fond de l'application : un décor calculé (views/_fond_decor.php) ou l'image
// personnalisée, au choix (?p=apparence, FONDS_DECOR). L'image ne concerne que
// les pages connectées — elle est posée en CSS sur body.has-sidebar::before par
// couleurs_css_vars() — et les pages hors session gardent donc un décor, le
// défaut si c'est l'image qui est choisie.
$fondDecor        = param_fond_decor();
$fondPersonnalise = $fondDecor === 'image';
$fondDecorAuth    = $fondPersonnalise ? 'maillage' : $fondDecor;
// Calculés ici (avant <head>, pas seulement pour la boucle du rail plus bas)
// pour pouvoir injecter module_couleur_css_vars($navActif) dans <head>.
$navGroupes = $u ? nav_groupes() : [];
$navActif   = $u ? nav_groupe_actif($navGroupes, $cur, (string) ($_GET['depuis'] ?? '')) : null;
?>
<!DOCTYPE html>
<?php // data-theme n'est posé que pour un choix EXPLICITE : en mode « auto »
      // l'attribut reste absent, et c'est la media query prefers-color-scheme
      // qui décide. Rendu côté serveur, donc aucun scintillement au chargement
      // et aucun JavaScript — c'est l'avantage d'un réglage stocké en base. ?>
<html lang="fr"<?= param_theme() !== 'auto' ? ' data-theme="' . e(param_theme()) . '"' : '' ?>>
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
    <?php // Favicone : les versions réduites du logo employeur si elles ont été
          // fournies, sinon les logos normaux (logo_petit_variante()). L'onglet
          // est dessiné par le navigateur, qui suit le thème du SYSTÈME et non
          // celui réglé dans l'application — d'où la media query plutôt que
          // param_theme().
          //
          // ⚠️ L'ORDRE compte, et le fond sombre passe donc EN PREMIER. Le
          // support de l'attribut media sur un rel="icon" est très inégal : un
          // navigateur qui l'ignore retient le DERNIER lien, quel que soit le
          // thème. Le dernier doit donc être la variante pour fond clair, celle
          // d'une barre d'onglets par défaut — sans quoi un système en
          // apparence claire hérite du logo pensé pour un fond sombre (constaté
          // en préparant la 2.8.8, qui pose cette favicone). Un navigateur qui comprend media, lui, choisit
          // correctement dans les deux sens : les deux liens s'excluent. ?>
    <?php $favClair = logo_petit_variante('clair'); $favSombre = logo_petit_variante('sombre'); ?>
    <?php if ($favSombre !== null && $favSombre !== $favClair): ?>
    <link rel="icon" href="<?= e(param_logo($favSombre)) ?>" media="(prefers-color-scheme: dark)">
    <?php endif; ?>
    <?php if ($favClair !== null): ?>
    <link rel="icon" href="<?= e(param_logo($favClair)) ?>"<?= $favSombre !== null && $favSombre !== $favClair ? ' media="(prefers-color-scheme: light)"' : '' ?>>
    <?php endif; ?>
    <?= couleurs_css_vars() ?>
    <?= module_couleur_css_vars($navActif) ?>
</head>
<?php // La classe du décor porte le fond de <body> qui va avec lui : chaque
      // décor a le sien (assets/app.css, section « Décors de fond »). ?>
<body class="<?= $u ? 'has-sidebar' : 'auth-bg' ?> fond-<?= e($u ? $fondDecor : $fondDecorAuth) ?>">
<?php if ($u): ?>
<?php if (!$fondPersonnalise) { require __DIR__ . '/_fond_decor.php'; } ?>
<?php // Burger AVANT le logo : la navigation est à gauche sur bureau (le rail),
      // elle l'est donc aussi sur téléphone — bouton, tiroir et bouton de
      // fermeture, tous du même côté. ?>
<header class="mobile-bar">
    <button type="button" class="burger" id="burger" title="Menu" aria-label="Menu" aria-expanded="false">
        <?= icon('menu') ?>
    </button>
    <?php // La barre est large : le logo normal y a sa place, et la version
          // réduite ne sert que de repli si aucun logo large n'est configuré. ?>
    <?php $vMbar = $logoSombre !== '' ? 'sombre' : logo_petit_variante('sombre'); ?>
    <?php // Le logo ramène au tableau de bord : c'est le geste attendu d'un
          // logo d'application, et sur téléphone le rail est replié. ?>
    <a href="?p=resumes" class="mbar-accueil" title="Tableau de bord" aria-label="Tableau de bord">
        <?php if ($vMbar !== null): ?><img src="<?= e(param_logo($vMbar)) ?>" alt="<?= e($nomEmployeur) ?>" class="mbar-logo<?= str_starts_with($vMbar, 'mini_') ? ' mbar-logo-mini' : '' ?>"><?php else: ?><span class="mbar-name"><?= e($nomEmployeur) ?></span><?php endif; ?>
    </a>
</header>
<div class="scrim" id="scrim"></div>
<aside class="sidebar" id="sidebar">
    <div class="side-brand">
        <div class="side-brand-txt">
            <?php
            // Le rail passe d'un fond clair à un fond sombre selon le thème : le
            // logo doit suivre. En mode « automatique » le serveur ignore le
            // réglage du système, on rend donc LES DEUX variantes et c'est le CSS
            // qui montre la bonne (.side-logo-clair / .side-logo-sombre) — sans
            // JavaScript ni scintillement.
            //
            // Le rail est étroit : on y préfère la version réduite du logo
            // quand elle existe, et logo_petit_variante() gère le repli — la
            // version réduite du bon fond, sinon le logo normal, sinon les
            // variantes de l'autre fond. Mieux vaut un logo imparfaitement
            // contrasté que pas de logo du tout.
            $vRailClair  = logo_petit_variante('clair');
            $vRailSombre = logo_petit_variante('sombre');
            $logoRailClair  = $vRailClair  !== null ? param_logo_src($vRailClair)  : '';
            $logoRailSombre = $vRailSombre !== null ? param_logo_src($vRailSombre) : $logoRailClair;
            // Une version réduite est carrée : elle a droit à plus de hauteur
            // que le logo large, qui lui doit tenir dans la largeur du rail.
            $clsRailClair  = 'side-logo side-logo-clair'  . (str_starts_with((string) $vRailClair, 'mini_')  ? ' side-logo-mini' : '');
            $clsRailSombre = 'side-logo side-logo-sombre' . (str_starts_with((string) $vRailSombre, 'mini_') ? ' side-logo-mini' : '');
            ?>
            <?php // Le logo du rail est un lien vers le tableau de bord — le
                  // geste qu'on tente d'instinct sur le logo d'une application. ?>
            <a href="?p=resumes" class="side-accueil" title="Tableau de bord" aria-label="Tableau de bord">
            <?php if ($logoRailClair !== ''): ?>
                <img src="<?= e($logoRailClair) ?>" alt="<?= e($nomEmployeur) ?>" class="<?= $clsRailClair ?>">
                <img src="<?= e($logoRailSombre) ?>" alt="<?= e($nomEmployeur) ?>" class="<?= $clsRailSombre ?>">
            <?php else: ?><span class="side-name"><?= e($nomEmployeur) ?></span><?php endif; ?>
            </a>
            <span class="side-sub">Gestion des salaires</span>
        </div>
        <button type="button" class="side-close" id="side-close" title="Fermer" aria-label="Fermer"><?= icon('x') ?></button>
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
        <?php // &depuis= comme les onglets de module (_module_tabs_render.php) :
              // ?p=structures appartient à trois groupes (booking, facturation,
              // événements), et sans ce marqueur le rail y arrivait sans dire d'où,
              // laissant nav_groupe_actif() deviner — et la liste afficher des
              // colonnes qui ne concernent pas le module d'où l'on vient. ?>
        <a href="?p=<?= array_key_first($navG[2]) ?>&depuis=<?= e($navCle) ?>" class="rail-btn <?= $navActif === $navCle ? 'on' : '' ?>" title="<?= e($navG[0]) ?>" style="--rail-accent: <?= e(MODULE_COULEURS[$navCle] ?? '') ?>">
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
            <button class="side-avatar" id="side-avatar-btn" title="Mon compte" aria-label="Mon compte" aria-haspopup="true" aria-expanded="false">
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
<?php // Le bouton « Fermer » de l'aperçu est posé dans la barre d'outils de la
      // page affichée, qui vit dans l'iframe — laquelle n'a pas le sprite de
      // CETTE page. Son icône doit donc être un SVG complet, pas un <use>. ?>
<?php $spriteAvant = icones_mode_sprite(); icones_mode_sprite(false);
      $icoFermerApercu = icon('x'); icones_mode_sprite($spriteAvant); ?>
<div id="preview-modal" hidden aria-modal="true" role="dialog" aria-label="Aperçu">
    <div id="preview-modal-inner">
        <?php // Repli : ce bouton flottant ne paraît que si la page affichée n'a
              // pas de barre d'outils où poser « Fermer ». Toutes en ont une
              // aujourd'hui — il couvre le cas d'une page qui n'en aurait pas,
              // plutôt que de laisser la fenêtre sans sortie visible. ?>
        <button id="preview-modal-close" hidden title="Fermer l'aperçu" aria-label="Fermer l'aperçu"><?= icon('x') ?></button>
        <iframe id="preview-modal-frame" src="" title="Aperçu"></iframe>
    </div>
</div>
<script nonce="<?= e(csp_nonce()) ?>">
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
    // Destination d'une ligne cliquable : data-href s'il est posé, sinon le lien
    // de titre que la ligne contient déjà. ?p=structures ne pose plus l'attribut
    // — il recopiait ce href à l'identique sur 2959 lignes — et les listes qui
    // n'ont pas de .titre-lien continuent de le fournir.
    function go(el) {
        const u = el.getAttribute('data-href') || el.querySelector('a.titre-lien')?.getAttribute('href');
        if (u) location.href = u;
    }
    document.querySelectorAll('tr.row-link').forEach(row => {
        // .plan-grip : poignée de glisser-déposer (?p=spectacles). Elle portait
        // un onclick="event.stopPropagation()" ; les attributs de gestionnaire
        // ayant été supprimés pour permettre le durcissement de la CSP, son
        // exclusion se déclare ici, comme celle des autres éléments interactifs.
        row.addEventListener('click', e => { if (!e.target.closest('a,button,input,form,.cat-search-list,.plan-grip')) go(row); });
        row.addEventListener('keydown', e => {
            // e.target !== row : la ligne ne s'active au clavier que si c'est
            // ELLE qui a le focus. Sans ce test, une frappe dans un champ de la
            // ligne remontait jusqu'ici — taper une espace dans le champ
            // « nouvelle étiquette » de ?p=structures naviguait vers la fiche
            // au lieu d'écrire l'espace, et les étiquettes en deux mots étaient
            // impossibles à saisir. Le gestionnaire de clic juste au-dessus
            // faisait déjà cette exclusion ; celui-ci l'avait oubliée.
            if (e.target !== row) return;
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

    // Fenêtre d'aperçu (liens [data-preview]). DEUX formats, et pas un de plus :
    //   « a4 » (défaut) — un document destiné au papier : facture, fiche de
    //          salaire, certificat, bilan. La fenêtre a la largeur d'une feuille.
    //   « ajuste »      — un contenu qui n'est pas une feuille : le tableau de
    //          l'export SUISA, par exemple. La fenêtre prend la place disponible.
    // Le format se déclare sur le lien (data-preview="ajuste"), et non dans la
    // page cible : la fenêtre s'ouvre avant que l'iframe ait chargé, elle doit
    // donc connaître sa taille tout de suite.
    const previewModal = document.getElementById('preview-modal');
    const previewFrame = document.getElementById('preview-modal-frame');
    const previewClose = document.getElementById('preview-modal-close');
    function openPreview(url, format) {
        previewModal.classList.toggle('preview-ajuste', format === 'ajuste');
        previewFrame.style.height = '';   // remis à la hauteur du format courant
        previewFrame.src = url;
        previewClose.hidden = false; // masqué au chargement si la page a une barre d'outils
        previewModal.removeAttribute('hidden');
        document.body.style.overflow = 'hidden';
    }
    function closePreview() {
        previewModal.setAttribute('hidden', '');
        previewFrame.src = '';
        previewFrame.style.height = '';
        document.body.style.overflow = '';
    }
    // Format « ajuste » : la hauteur du cadre suit celle du contenu, plafonnée à
    // la place disponible. Sans cette mesure, l'iframe occuperait toute la
    // fenêtre même pour trois lignes de tableau — un cadre vide sous le contenu.
    function ajusterHauteur() {
        if (!previewModal.classList.contains('preview-ajuste')) return;
        try {
            const doc = previewFrame.contentDocument;
            const dispo = previewModal.clientHeight - 64; // padding 32px en haut et en bas
            previewFrame.style.height = Math.min(doc.documentElement.scrollHeight + 2, dispo) + 'px';
        } catch (err) {}
    }
    document.addEventListener('click', e => {
        const a = e.target.closest('a[data-preview]');
        if (!a || e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey) return;
        e.preventDefault();
        openPreview(a.href, a.dataset.preview);
    });
    previewClose.addEventListener('click', closePreview);
    previewModal.addEventListener('click', e => { if (e.target === previewModal) closePreview(); });
    document.addEventListener('keydown', e => {
        if (e.key === 'Escape' && !previewModal.hasAttribute('hidden')) closePreview();
    });
    // « Fermer » rejoint la barre d'outils de la page affichée (même origine →
    // accès DOM autorisé), tout à droite : c'est un bouton comme les autres, à
    // la fin de la rangée des actions, et non une pastille posée par-dessus le
    // document. Il est injecté d'ici plutôt qu'écrit dans les huit vues
    // d'impression : ces pages s'ouvrent aussi seules, hors de la fenêtre
    // d'aperçu, où « Fermer » n'aurait rien à fermer.
    const fermerHtml = <?= json_encode($icoFermerApercu . ' Fermer', JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
    previewFrame.addEventListener('load', () => {
        ajusterHauteur();
        try {
            const doc = previewFrame.contentDocument;
            const barre = doc.querySelector('.print-toolbar');
            if (barre && !barre.querySelector('.print-toolbar-fermer')) {
                const bouton = doc.createElement('button');
                bouton.type = 'button';
                bouton.className = 'btn ghost print-toolbar-fermer';
                bouton.innerHTML = fermerHtml;
                bouton.addEventListener('click', closePreview);
                barre.appendChild(bouton);
            }
            previewClose.hidden = !!barre;
            doc.addEventListener('keydown', ev => {
                if (ev.key === 'Escape') { ev.stopPropagation(); closePreview(); }
            }, true);
        } catch(err) {}
    });
})();
</script>
<?php else: ?>
<?php $fondDecor = $fondDecorAuth; require __DIR__ . '/_fond_decor.php'; ?>
<main class="auth-wrap">
    <?php
    // Le fond de connexion s'assombrit avec le thème, exactement comme le rail :
    // le logo doit suivre, sinon c'est la variante à encre foncée qui se retrouve
    // sur fond sombre, où elle ne se voit plus. Même mécanique que .side-logo
    // plus haut : les DEUX variantes sont rendues côté serveur et le CSS montre
    // la bonne — en mode « automatique », le serveur ignore le réglage système,
    // et c'est la seule façon de trancher sans JavaScript ni scintillement.
    // Même repli aussi : une seule variante configurée sert aux deux thèmes.
    $logoAuthClair  = $logoClair !== '' ? $logoClair : $logoSombre;
    $logoAuthSombre = $logoSombre !== '' ? $logoSombre : $logoAuthClair;
    ?>
    <?php if ($logoAuthClair !== ''): ?>
        <img src="<?= e($logoAuthClair) ?>" alt="<?= e($nomEmployeur) ?>" class="auth-logo auth-logo-clair">
        <img src="<?= e($logoAuthSombre) ?>" alt="<?= e($nomEmployeur) ?>" class="auth-logo auth-logo-sombre">
    <?php else: ?><div class="auth-name"><?= e($nomEmployeur) ?></div><?php endif; ?>
    <?php require $contentView; ?>
    <a class="side-powered auth-powered" href="https://github.com/nivivier/Lasso" target="_blank" rel="noopener">
        <img src="<?= e(asset_data_uri_mini('assets/lasso.png', 32)) ?>" alt="" class="side-powered-logo"> Lasso <span class="side-version">v<?= e(maj_version_locale()) ?></span>
    </a>
</main>
<?php endif; ?>
</body>
</html>
