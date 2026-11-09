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
// Le titre de la page, et le module en surtitre au-dessus. Avant que le rail
// ne porte la navigation, ce <h1> disait le MODULE et la rangée d'onglets
// disait la page ; le rail dit maintenant les deux.
//
// $ntPage reste VIDE sur une route secondaire — la fiche d'une structure, le
// formulaire d'un employé. Ces écrans portent déjà leur propre en-tête, avec
// le nom de l'enregistrement et un retour vers la liste : un bandeau qui
// annoncerait le module au-dessus leur poserait un titre de plus, plus gros
// que celui de l'enregistrement et disant moins. Le bandeau se tait donc.
$ntPage = '';
$ntSur  = $ntLabel;
foreach ($ntOnglets as $ntRouteCle => $ntO) {
    if (!in_array($ntCur, $ntO[1], true)) {
        continue;
    }
    // Une sous-page prend le titre, et son onglet rejoint le surtitre.
    if (!empty($ntO[4]) && isset($ntO[4][$ntCur])) {
        $ntSur  = $ntLabel . ' › ' . (string) $ntO[0];
        $ntPage = (string) $ntO[4][$ntCur];
    } elseif ((string) $ntRouteCle === $ntCur) {
        $ntPage = (string) $ntO[0];
    }
    break;
}
// ⚠️ Un bandeau SANS titre ne rend plus rien du tout. Il restait rendu puis
// effacé par le CSS tant qu'il portait la rangée d'onglets du téléphone ;
// celle-ci est partie, et il ne lui restait qu'une paire de <div> vides dans
// chaque réponse HTTP.
$ntMuet = $ntPage === '';
if ($ntMuet) {
    return;
}
?>
<div class="page-head-band<?= isset($ntBandClasse) ? ' ' . e($ntBandClasse) : '' ?>">
<div class="page-head">
    <?php // La ligne haute : le module à gauche, les actions de la page à droite.
          // Le créneau est rempli par le script de layout.php, qui y remonte le
          // bloc .head-actions de la vue — vide tant qu'il n'y en a pas, et
          // effacé par le CSS dans ce cas. ?>
    <div class="page-head-haut">
        <span class="page-head-sur"><?= e($ntSur) ?></span>
    </div>
    <?php // Le titre et les actions tiennent la même ligne : le fil d'Ariane
          // n'est qu'une étiquette, lui donner une rangée de 40px pour loger
          // des boutons éloignait le titre de tout ce qui l'entoure. ?>
    <div class="page-head-title page-head-titre-page">
        <h1><?= e($ntPage) ?></h1>
        <?php // Créneau de la barre d'outils (recherche + filtres + bascule de
              // vue). Le script de layout.php l'y remonte quand la ligne du
              // titre a la place de la tenir, et la laisse en dessous sinon :
              // ce sont les mêmes nœuds, déplacés, jamais deux rendus. ?>
        <span class="page-head-outils" id="page-head-outils"></span>
        <span class="page-head-actions" id="page-head-actions"></span>
        <?php // Les actions SECONDAIRES de la page, derrière les trois points :
              // le script de layout.php y range tout ce qui n'est pas l'action
              // principale. Rendu ici et non fabriqué en JavaScript parce que
              // icon() n'écrit le glyphe dans le sprite de la page que s'il est
              // demandé au rendu (lib/helpers/icones.php). Replié tant qu'il
              // est vide. ?>
        <details class="menu-deroulant page-head-plus" id="page-head-plus" hidden>
            <summary class="btn ghost icon-only" title="Autres actions" aria-label="Autres actions"><?= icon('dots') ?></summary>
            <div class="menu-deroulant-panneau" id="page-head-plus-panneau"></div>
        </details>
    </div>
</div>
</div>
