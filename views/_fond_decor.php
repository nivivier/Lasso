<?php
// Fond calculé de l'application : quatre décors au choix (?p=apparence,
// FONDS_DECOR), rendus ici en SVG en ligne. Sert à la fois aux pages non
// connectées (.auth-bg, autour de <main class="auth-wrap">) et aux pages
// connectées (.has-sidebar), tant que le choix n'est pas « image » — dans ce
// cas c'est couleurs_css_vars() qui pose l'image sur body.has-sidebar::before.
// Un seul partiel pour ne jamais avoir deux copies d'un même décor à faire
// évoluer en parallèle.
//
// Tous dérivent de --primary/--highlight via color-mix() (voir assets/app.css,
// section « Décors de fond ») : ils suivent donc les couleurs de l'employeur et
// le thème, sans qu'aucune couleur soit écrite ici. Le fond de <body> qui va
// avec chaque décor est posé par la classe body.fond-<décor> (views/layout.php).
//
// ⚠️ Le conteneur de chaque décor s'appelle .decor-<nom>, JAMAIS .fond-<nom> :
// cette dernière est la classe de <body>, et une règle de descendance comme
// « .fond-grille svg { width: 100% } » se serait appliquée à toutes les icônes
// de la page.
//
// $fondDecor est fourni par l'appelant (views/layout.php) : 'maillage',
// 'vagues', 'grille' ou 'courbes'.
//
// $fondIdPrefixe préfixe les identifiants SVG (filtres, motifs, masques) : le
// même décor peut être rendu DEUX fois dans une page — le fond lui-même et son
// aperçu dans ?p=apparence —, et deux id identiques feraient pointer les deux
// url(#…) sur le premier. Vide par défaut, donc rien ne change pour le fond.
/** @var string $fondDecor */
/** @var ?string $fondIdPrefixe */
$fondIdPrefixe = $fondIdPrefixe ?? '';
?>
<?php if ($fondDecor === 'maillage'): ?>
<?php // Quatre taches de couleur très floutées (feGaussianBlur) et un grain
      // fin (feTurbulence) : un champ de couleur plutôt qu'une forme, qui ne
      // se démode pas comme un motif reconnaissable. ?>
<div class="decor-mesh" aria-hidden="true">
    <svg viewBox="0 0 1200 800" preserveAspectRatio="xMidYMid slice">
        <defs>
            <filter id="<?= e($fondIdPrefixe) ?>mesh-flou" x="-30%" y="-30%" width="160%" height="160%">
                <feGaussianBlur stdDeviation="110"/>
            </filter>
            <filter id="<?= e($fondIdPrefixe) ?>mesh-grain">
                <feTurbulence type="fractalNoise" baseFrequency="0.85" numOctaves="3" stitchTiles="stitch"/>
                <feColorMatrix type="saturate" values="0"/>
            </filter>
        </defs>
        <g filter="url(#<?= e($fondIdPrefixe) ?>mesh-flou)">
            <ellipse class="mesh-1" cx="120" cy="90" rx="420" ry="320"/>
            <ellipse class="mesh-2" cx="1080" cy="180" rx="340" ry="300"/>
            <ellipse class="mesh-3" cx="640" cy="820" rx="560" ry="340"/>
            <ellipse class="mesh-4" cx="1180" cy="700" rx="300" ry="260"/>
        </g>
        <rect class="mesh-grain" width="1200" height="800" filter="url(#<?= e($fondIdPrefixe) ?>mesh-grain)"/>
    </svg>
</div>
<?php elseif ($fondDecor === 'grille'): ?>
<?php // Un quadrillage qui s'efface vers les bords (masque en dégradé radial),
      // deux halos diffus et deux axes appuyés qui se croisent au centre. ?>
<div class="decor-grille" aria-hidden="true">
    <svg viewBox="0 0 1200 800" preserveAspectRatio="xMidYMid slice">
        <defs>
            <pattern id="<?= e($fondIdPrefixe) ?>gr-carreau" width="48" height="48" patternUnits="userSpaceOnUse">
                <path class="grille-trait" d="M48 0H0V48" fill="none" stroke-width="1"/>
            </pattern>
            <radialGradient id="<?= e($fondIdPrefixe) ?>gr-fondu" cx="50%" cy="46%" r="62%">
                <stop offset="0%" stop-color="#fff" stop-opacity="1"/>
                <stop offset="55%" stop-color="#fff" stop-opacity=".45"/>
                <stop offset="100%" stop-color="#fff" stop-opacity="0"/>
            </radialGradient>
            <mask id="<?= e($fondIdPrefixe) ?>gr-masque"><rect width="1200" height="800" fill="url(#<?= e($fondIdPrefixe) ?>gr-fondu)"/></mask>
            <filter id="<?= e($fondIdPrefixe) ?>gr-flou" x="-40%" y="-40%" width="180%" height="180%">
                <feGaussianBlur stdDeviation="120"/>
            </filter>
        </defs>
        <g filter="url(#<?= e($fondIdPrefixe) ?>gr-flou)">
            <ellipse class="grille-halo-1" cx="600" cy="360" rx="420" ry="300"/>
            <ellipse class="grille-halo-2" cx="1120" cy="740" rx="320" ry="240"/>
        </g>
        <rect width="1200" height="800" fill="url(#<?= e($fondIdPrefixe) ?>gr-carreau)" mask="url(#<?= e($fondIdPrefixe) ?>gr-masque)"/>
        <g mask="url(#<?= e($fondIdPrefixe) ?>gr-masque)">
            <line class="grille-axe" x1="0" y1="368" x2="1200" y2="368" stroke-width="1"/>
            <line class="grille-axe" x1="600" y1="0" x2="600" y2="800" stroke-width="1"/>
        </g>
    </svg>
</div>
<?php elseif ($fondDecor === 'courbes'): ?>
<?php // Seize lignes parallèles qui ondulent, comme une carte topographique —
      // un motif qui tient au trait, pas à l'aplat. La dixième est accentuée :
      // elle passe à hauteur de carte, et donne son seul repère au décor.
      // Les chemins sont calculés ici plutôt qu'écrits à la main : seize
      // courbes copiées-collées auraient été seize occasions de divergence. ?>
<div class="decor-courbes" aria-hidden="true">
    <svg viewBox="0 0 1200 800" preserveAspectRatio="xMidYMid slice">
        <?php for ($i = 0; $i < 16; $i++): ?>
        <?php
        $cnY   = 60 + $i * 52;
        $cnAmp = 46 + $i * 3;
        $cnAcc = $i === 9;
        ?>
        <path class="<?= $cnAcc ? 'cn-accent' : 'cn-ligne' ?>" fill="none" stroke-width="<?= $cnAcc ? '2' : '1.2' ?>"
              d="M-100 <?= $cnY ?> C 180 <?= $cnY - $cnAmp ?>, 380 <?= $cnY + $cnAmp ?>, 620 <?= $cnY ?> S 1060 <?= $cnY - $cnAmp ?>, 1300 <?= $cnY ?>"/>
        <?php endfor; ?>
    </svg>
</div>
<?php else: ?>
<?php // « vagues » : le décor historique de l'application (concept « Fluid Wave
      // Background » fourni par l'utilisateur), conservé comme un choix parmi
      // les autres — c'est ce que voient les installations déjà en service. ?>
<div class="wave-decor" aria-hidden="true">
    <div class="wave-blur one"></div>
    <div class="wave-blur two"></div>
    <svg class="wave-shape wave-shape-1" viewBox="0 0 1600 700" preserveAspectRatio="none"><path d="M0 380 C250 120 520 650 900 380 C1200 160 1450 350 1600 220 L1600 700 L0 700 Z"/></svg>
    <svg class="wave-shape wave-shape-2" viewBox="0 0 1600 700" preserveAspectRatio="none"><path d="M0 460 C250 250 600 680 950 450 C1250 270 1450 420 1600 300 L1600 700 L0 700 Z"/></svg>
    <svg class="wave-shape wave-shape-3" viewBox="0 0 1600 700" preserveAspectRatio="none"><path d="M0 420 C180 300 350 330 520 470 C720 640 900 640 1080 470 C1280 300 1450 350 1600 430 L1600 560 C1400 480 1250 500 1080 580 C850 700 650 690 480 540 C300 400 150 460 0 520 Z"/></svg>
    <svg class="wave-shape wave-shape-4" viewBox="0 0 1600 700" preserveAspectRatio="none"><path d="M0 560 C300 420 650 680 1000 520 C1300 380 1500 500 1600 400 L1600 700 L0 700 Z"/></svg>
</div>
<?php endif; ?>
