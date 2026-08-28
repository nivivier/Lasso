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
$ptGroupes = [];

// Application : administration (écriture cœur), voir index.php.
if (peut_ecrire('coeur')) {
    $ptGroupes['application'] = ['Application', [
        'maj'                => 'Mises à jour',
        'apparence'          => 'Apparence',
        'parametres_modules' => 'Modules',
        'comptes'            => 'Utilisateurs',
        'diagnostic'         => 'Serveur',
    ]];
}

$ptGroupes['employeur'] = ['Employeur', ['employeur' => 'Employeur']];
$ptGroupes['emails']    = ['E-mails', ['emails' => 'E-mails']];

if (module_actif('salaires') && peut_lire('salaires')) {
    $ptGroupes['taux'] = ['Taux', [
        'taux'          => 'Charges sociales et patronales',
        'taux_horaires' => 'Salaires horaires et unités',
    ]];
}

$ptCatSections = ['parametres_pays' => 'Pays'];
if (module_actif('booking') && peut_lire('booking')) {
    $ptCatSections['parametres_structures'] = 'Catégories';
    $ptCatSections['parametres_tags']       = 'Étiquettes';
}
$ptGroupes['categories'] = ['Catégories', $ptCatSections];

if (module_actif('evenements') && peut_lire('evenements')) {
    $ptGroupes['evenements'] = ['Événements', ['parametres_evenements' => 'Événements']];
}

// Données : Importer/Exporter/Incohérences regroupés sous un seul onglet
// principal, avec ces 3 sections en sous-onglets.
// Importer couvre plusieurs routes de traitement (selon les modules actifs) —
// le sous-onglet pointe vers la première, toutes comptent comme la section
// active (aliases, voir la mise en surbrillance des sous-onglets plus bas :
// une section-alias est signalée active via le 3ᵉ élément du groupe, pas via
// sa propre clé, d'où le cas particulier « première section du groupe »).
$ptRoutesImport = [];
if (module_actif('salaires')    && peut_lire('salaires'))    $ptRoutesImport[] = 'import_fiches';
if (module_actif('facturation') && peut_lire('facturation')) $ptRoutesImport[] = 'import_factures';
if (module_actif('compta')      && peut_lire('compta'))      $ptRoutesImport[] = 'import_ecritures';
if (module_actif('evenements')  && peut_lire('evenements'))  $ptRoutesImport[] = 'import_evenements';
if (module_actif('booking')     && peut_lire('booking'))     $ptRoutesImport[] = 'import_structures';
$ptDonneesSections = [];
if ($ptRoutesImport) {
    $ptDonneesSections[$ptRoutesImport[0]] = 'Importer';
}
$ptDonneesSections['export'] = 'Exporter';
if (peut_ecrire('coeur')) {
    $ptDonneesSections['dev'] = 'Incohérences';
}
$ptGroupes['donnees'] = ['Données', $ptDonneesSections, $ptRoutesImport];

// Icônes des onglets principaux — même esprit que nav_groupes() (lib/modules.php),
// une icône par groupe, purement décorative (pas de sens fonctionnel supplémentaire).
$ptIcones = [
    'application' => 'settings',
    'employeur'   => 'building',
    'emails'      => 'mail',
    'taux'        => 'percent',
    'categories'  => 'blocks',
    'evenements'  => 'calendar',
    'donnees'     => 'import',
];

$ptCurParam = $_GET['p'] ?? '';

// Groupe actif : celui dont une section (ou un alias) correspond à la route.
$ptGroupeActif = null;
foreach ($ptGroupes as $ptCle => $ptG) {
    if (in_array($ptCurParam, array_keys($ptG[1]), true) || in_array($ptCurParam, $ptG[2] ?? [], true)) {
        $ptGroupeActif = $ptCle;
        break;
    }
}
$ptSectionsActives = $ptGroupeActif !== null ? $ptGroupes[$ptGroupeActif][1] : [];

// Arrivée depuis un module (?depuis=booking sur Pays/Catégories/Étiquettes,
// posé par l'onglet « Catégories » du bandeau Booking) : c'est le bandeau DU
// MODULE qui coiffe la page, pas celui des paramètres — sinon cliquer cet
// onglet donnait l'impression de quitter le booking. Les sous-onglets
// (Pays / Catégories / Étiquettes) restent les mêmes et emportent la
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
<div class="page-head-band">
<div class="page-head">
    <div class="page-head-title">
        <h1>Paramètres</h1>
    </div>
    <nav class="module-tabs">
        <?php foreach ($ptGroupes as $ptCle => $ptG): ?>
            <a href="?p=<?= array_key_first($ptG[1]) ?>" class="module-tab <?= $ptGroupeActif === $ptCle ? 'on' : '' ?>"><?= icon($ptIcones[$ptCle] ?? 'settings') ?> <?= e($ptG[0]) ?></a>
        <?php endforeach; ?>
    </nav>
</div>
</div>
<?php endif; ?>
<?php if (count($ptSectionsActives) > 1): ?>
<nav class="param-subtabs">
    <?php
    // Une section-alias (ex. Importer, voir plus haut) n'est jamais elle-même
    // la route courante : sa mise en surbrillance passe par les alias du
    // groupe (3ᵉ élément), rattachés par convention à la toute première section.
    $ptPremiereSection = array_key_first($ptSectionsActives);
    $ptAliasesGroupe = $ptGroupes[$ptGroupeActif][2] ?? [];
    ?>
    <?php foreach ($ptSectionsActives as $ptRoute => $ptLib): ?>
        <?php $ptOn = $ptCurParam === $ptRoute || ($ptRoute === $ptPremiereSection && in_array($ptCurParam, $ptAliasesGroupe, true)); ?>
        <a href="?p=<?= $ptRoute ?><?= $ptSuffixeDepuis ?>" class="<?= $ptOn ? 'on' : '' ?>"><?= e($ptLib) ?></a>
    <?php endforeach; ?>
</nav>
<?php endif; ?>
