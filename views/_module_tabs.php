<?php
// Rail d'icônes + bandeau d'onglets de module. Calcule le groupe de
// nav_groupes() actif pour la route courante (lib/modules.php). Variables
// préfixées « nt » — même convention que « pt » dans _param_tabs.php, pour
// éviter toute collision avec extract($data) dans render().
//
// Ce fichier ne produit AUCUN HTML : $ntLabel sert au <h1> de la page (dans
// .page-head-title, à la position exacte que chaque page choisit déjà),
// $ntOnglets/$ntCle au rendu de la rangée d'onglets elle-même, fait par
// _module_tabs_render.php — requis séparément, là où la rangée doit
// apparaître dans .page-head (en général : après .head-actions, avant
// .filters, comme un enfant direct pleine largeur de .page-head).
$ntGroupes = nav_groupes();
$ntCle     = nav_groupe_actif($ntGroupes, (string) ($_GET['p'] ?? ''), (string) ($_GET['depuis'] ?? ''));
$ntLabel   = $ntCle !== null ? $ntGroupes[$ntCle][0] : '';
$ntOnglets = $ntCle !== null ? $ntGroupes[$ntCle][2] : [];
// Les sous-onglets de l'onglet ACTIF, s'il en a (5e élément de son entrée dans
// nav_groupes()). Même dispositif à deux niveaux que les Paramètres, et même
// rangée .param-subtabs pour le rendre : un onglet qui porte deux écrans de la
// même matière les montre sous lui plutôt que de prendre deux places dans le
// bandeau. Vide partout ailleurs, donc rien ne s'affiche.
$ntSousOnglets = [];
$ntCur = (string) ($_GET['p'] ?? '');
foreach ($ntOnglets as $ntO) {
    if (!empty($ntO[4]) && in_array($ntCur, $ntO[1], true)) {
        $ntSousOnglets = $ntO[4];
        break;
    }
}
