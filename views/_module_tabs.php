<?php
// Rail d'icônes + bandeau d'onglets de module. Calcule le groupe de
// nav_groupes() actif pour la route courante (lib/modules.php). Variables
// préfixées « nt » — même convention que « pt » dans _param_tabs.php, pour
// éviter toute collision avec extract($data) dans render().
//
// Ce fichier ne produit AUCUN HTML : il résout le groupe, et le bandeau
// (_page_head_band.php) en tire le surtitre, le titre de la page et le nom de
// la sous-page ouverte. Il n'y a plus de rangée d'onglets à rendre — le rail
// porte les trois niveaux (docs/UI.md § 9 ter) —, mais ce calcul-là reste : il
// est la seule source du nom d'un écran.
$ntGroupes = nav_groupes();
$ntCle     = nav_groupe_actif($ntGroupes, (string) ($_GET['p'] ?? ''), (string) ($_GET['depuis'] ?? ''));
$ntLabel   = $ntCle !== null ? $ntGroupes[$ntCle][0] : '';
$ntOnglets = $ntCle !== null ? $ntGroupes[$ntCle][2] : [];
$ntCur = (string) ($_GET['p'] ?? '');
