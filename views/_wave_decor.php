<?php
// Fond calculé (vagues + halos), utilisé à la fois par le fond des pages non
// connectées (.auth-bg, autour de <main class="auth-wrap">) et par défaut
// derrière les pages connectées (.has-sidebar, tant qu'aucune image de fond
// personnalisée n'est configurée — voir ?p=apparence, couleurs_css_vars()
// dans lib/helpers.php). Un seul partiel pour ne jamais avoir deux copies de
// ces mêmes vagues à faire évoluer en parallèle. Dérivé de --primary/--highlight
// (color-mix(), voir assets/app.css) : suit automatiquement la couleur de
// mise en évidence si l'employeur la personnalise. Concept (vagues SVG
// superposées) fourni par l'utilisateur.
?>
<div class="wave-decor" aria-hidden="true">
    <div class="wave-blur one"></div>
    <div class="wave-blur two"></div>
    <svg class="wave-shape wave-shape-1" viewBox="0 0 1600 700" preserveAspectRatio="none"><path d="M0 380 C250 120 520 650 900 380 C1200 160 1450 350 1600 220 L1600 700 L0 700 Z"/></svg>
    <svg class="wave-shape wave-shape-2" viewBox="0 0 1600 700" preserveAspectRatio="none"><path d="M0 460 C250 250 600 680 950 450 C1250 270 1450 420 1600 300 L1600 700 L0 700 Z"/></svg>
    <svg class="wave-shape wave-shape-3" viewBox="0 0 1600 700" preserveAspectRatio="none"><path d="M0 420 C180 300 350 330 520 470 C720 640 900 640 1080 470 C1280 300 1450 350 1600 430 L1600 560 C1400 480 1250 500 1080 580 C850 700 650 690 480 540 C300 400 150 460 0 520 Z"/></svg>
    <svg class="wave-shape wave-shape-4" viewBox="0 0 1600 700" preserveAspectRatio="none"><path d="M0 560 C300 420 650 680 1000 520 C1300 380 1500 500 1600 400 L1600 700 L0 700 Z"/></svg>
</div>
