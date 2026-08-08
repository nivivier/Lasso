<?php
// Bandeau de page pleine largeur (titre du groupe + rangée d'onglets) — un
// seul require pour le bloc <div class="page-head-band">...</div> répété à
// l'identique dans chaque vue retrofitée (calcul via _module_tabs.php, déjà
// requis par l'appelant AVANT celui-ci : ce fichier suppose $ntLabel déjà
// résolu, et ne fait que le rendu).
//
// $ntBandClasse (optionnel, à définir par la vue AVANT ce require) : classe
// CSS supplémentaire sur .page-head-band — utilisé par structures_liste.php/
// evenements_liste.php pour "carte-header" en vue carte.
?>
<div class="page-head-band<?= isset($ntBandClasse) ? ' ' . e($ntBandClasse) : '' ?>">
<div class="page-head">
    <div class="page-head-title">
        <h1><?= e($ntLabel) ?></h1>
    </div>
    <?php require __DIR__ . '/_module_tabs_render.php'; ?>
</div>
</div>
