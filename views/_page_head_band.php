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
    <?php // .page-head-titre-module : ce <h1> dit le MODULE, et la barre
          // supérieure le dit déjà sur téléphone — c'est par cette classe
          // qu'il s'efface alors (assets/app.css). Les <h1> qui nomment un
          // ENREGISTREMENT (une campagne, un certificat) ne la portent pas :
          // eux ne doublonnent rien. ?>
    <div class="page-head-title page-head-titre-module">
        <h1><?= e($ntLabel) ?></h1>
    </div>
    <?php require __DIR__ . '/_module_tabs_render.php'; ?>
</div>
</div>
<?php if (!empty($ntSousOnglets)): ?>
<?php // Second niveau : même rangée que celle des Paramètres, par le même
      // partiel, pour que deux niveaux d'onglets se lisent partout pareil. ?>
<?php
$stOnglets = $ntSousOnglets;
$stActif   = $ntCur;
$stSuffixe = '&depuis=' . rawurlencode((string) $ntCle);
require __DIR__ . '/_subtabs.php';
?>
<?php endif; ?>
