<?php
// Un élément de déroulé, en une ligne : l'intitulé puis son détail, séparés par
// des points médians. Le détail est en gris — c'est l'intitulé qu'on cherche du
// regard en parcourant la liste, le reste se lit une fois qu'on s'est arrêté.
//
// Attendu de l'appelant : $el (la ligne d'evenement_feuille, jointures comprises).
$flDetail = feuille_element_lignes($el);
?><b class="feuille-titre"><?= e(feuille_element_titre($el)) ?></b><?php
if ($flDetail): ?> <span class="muted feuille-detail"><?= e(implode(' · ', $flDetail)) ?></span><?php endif;
