<?php
// Barre d'onglets des paramètres, sur DEUX niveaux : groupes (onglets
// principaux) puis sections du groupe actif (sous-onglets). L'onglet et la
// section actifs sont déduits de la route courante (?p=…). Groupes/sections
// propres à un module sont masqués si celui-ci est désactivé (lib/modules.php)
// ou hors des droits de l'utilisateur.
//
// Chaque groupe : 'clé' => [ 'Libellé', [route => 'Section', …], [alias de route] ].
// Le lien du groupe pointe vers sa première section. Les alias (facultatifs)
// servent uniquement à repérer le groupe actif (ex. Importer : plusieurs routes
// de traitement comptent comme le même onglet).
//
// ⚠️ Les variables de ce partiel sont préfixées « pt » : render() fait
// extract($data) puis inclut ce fichier, donc tout nom générique ($groupes,
// $g…) écraserait une donnée de la vue appelante (cf. import_structures et son
// propre $groupes de regroupements). Ne pas réintroduire de nom générique ici.
// Les groupes et leurs sections vivent dans lib/modules.php
// (parametres_groupes()) : le gabarit en a besoin AVANT que cette vue ne
// s'exécute, pour poser « Paramètres » dans la barre supérieure sur téléphone.
$ptGroupes = parametres_groupes();

// Icônes des onglets principaux — même esprit que nav_groupes() (lib/modules.php),
// une icône par groupe, purement décorative (pas de sens fonctionnel supplémentaire).
$ptIcones = [
    'application' => 'settings',
    'employeur'   => 'building',
    'emails'      => 'mail',
    'valeurs'     => 'percent',
    'categories'  => 'blocks',
    'donnees'     => 'import',
];

$ptCurParam = $_GET['p'] ?? '';

// Groupe actif : celui dont une section (ou un alias) correspond à la route.
$ptGroupeActif = parametres_groupe_actif($ptGroupes, $ptCurParam);
$ptSectionsActives = $ptGroupeActif !== null ? $ptGroupes[$ptGroupeActif][1] : [];

// Arrivée depuis un module (?depuis=booking sur Pays/Catégories/Tags,
// posé par l'onglet « Catégories » du bandeau Booking) : c'est le bandeau DU
// MODULE qui coiffe la page, pas celui des paramètres — sinon cliquer cet
// onglet donnait l'impression de quitter le booking. Les sous-onglets
// (Pays / Catégories / Tags) restent les mêmes et emportent la
// provenance, pour qu'on ne retombe pas dans Paramètres en passant de l'un à
// l'autre. Sans ?depuis= valide — lien direct, favori, navigation depuis
// Paramètres — rien ne change.
$ptDepuis = (string) ($_GET['depuis'] ?? '');
$ptDansModule = false;
if ($ptDepuis !== '') {
    $ntGroupes = nav_groupes();
    $ntCle = nav_groupe_actif($ntGroupes, $ptCurParam, $ptDepuis);
    if ($ntCle === $ptDepuis) {
        $ptDansModule = true;
        $ntLabel   = $ntGroupes[$ntCle][0];
        $ntOnglets = $ntGroupes[$ntCle][2];
    }
}
$ptSuffixeDepuis = $ptDansModule ? '&depuis=' . rawurlencode($ptDepuis) : '';
?>
<?php if ($ptDansModule): ?>
<?php require __DIR__ . '/_page_head_band.php'; ?>
<?php else: ?>
<?php // Les Paramètres sont un volet du rail comme les autres depuis qu'il
      // porte toute la navigation (views/layout.php) : le bandeau se contente
      // donc de nommer la page — « Paramètres » en surtitre, le groupe en
      // titre —, et sa rangée d'onglets ne sert plus qu'au téléphone, où le
      // rail est un tiroir hors écran. ?>
<div class="page-head-band">
<div class="page-head">
    <?php
    // Même règle que pour un module : le titre nomme la PAGE ouverte, et ce qui
    // la contient passe en surtitre. Un groupe d'une seule section ne se répète
    // pas — « Employeur » suffit, « Paramètres › Employeur / Employeur » non.
    $ptSur   = 'Paramètres';
    $ptTitre = $ptGroupeActif !== null ? (string) $ptGroupes[$ptGroupeActif][0] : 'Paramètres';
    if ($ptGroupeActif !== null && count($ptSectionsActives) > 1) {
        $ptRouteTitre = in_array($ptCurParam, $ptGroupes[$ptGroupeActif][2] ?? [], true)
            ? (string) array_key_first($ptSectionsActives)
            : $ptCurParam;
        if (isset($ptSectionsActives[$ptRouteTitre])) {
            $ptSur   = 'Paramètres › ' . $ptTitre;
            $ptTitre = (string) $ptSectionsActives[$ptRouteTitre];
        }
    }
    ?>
    <?php // Mêmes places que sur un module : le fil d'Ariane seul sur sa ligne,
          // le titre et les actions sur la suivante. Le créneau d'actions était
          // resté sur la ligne du haut, ce qui la faisait grandir à la hauteur
          // d'un bouton — et tout l'en-tête descendait de quinze pixels sur les
          // seules pages de réglages qui ont des boutons (Projets, Pays). ?>
    <div class="page-head-haut">
        <span class="page-head-sur"><?= e($ptSur) ?></span>
    </div>
    <div class="page-head-title page-head-titre-page">
        <h1><?= e($ptTitre) ?></h1>
        <?php // Créneau de la barre d'outils, comme sur un module (voir
              // views/_page_head_band.php) : ?p=projets a une recherche, et
              // elle doit monter ici quand la ligne la tient. ?>
        <span class="page-head-outils" id="page-head-outils"></span>
        <span class="page-head-actions" id="page-head-actions"></span>
        <details class="menu-deroulant page-head-plus" id="page-head-plus" hidden>
            <summary class="btn ghost icon-only" title="Autres actions" aria-label="Autres actions"><?= icon('dots') ?></summary>
            <div class="menu-deroulant-panneau" id="page-head-plus-panneau"></div>
        </details>
    </div>
</div>
</div>
<?php endif; ?>
<?php // (Ici vivait la rangée de SOUS-onglets des Paramètres, rendue puis
      // effacée par le CSS depuis que le rail déplie les sections du groupe
      // ouvert. Partie avec celle des modules — voir docs/UI.md § 9 ter.) ?>
