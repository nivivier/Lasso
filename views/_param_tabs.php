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
        'diagnostic'         => 'Diagnostic du serveur',
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
    $ptCatSections['parametres_structures']       = 'Structures';
    $ptCatSections['parametres_lieux_categories'] = 'Lieux';
    $ptCatSections['parametres_tags']             = 'Étiquettes';
}
$ptGroupes['categories'] = ['Catégories', $ptCatSections];

if (module_actif('evenements') && peut_lire('evenements')) {
    $ptGroupes['evenements'] = ['Événements', ['parametres_evenements' => 'Événements']];
}

// Importer : un seul onglet couvrant plusieurs routes de traitement (selon les
// modules actifs) — le lien pointe vers la première, toutes comptent comme
// l'onglet actif (aliases).
$ptRoutesImport = [];
if (module_actif('salaires')    && peut_lire('salaires'))    $ptRoutesImport[] = 'import_fiches';
if (module_actif('facturation') && peut_lire('facturation')) $ptRoutesImport[] = 'import_factures';
if (module_actif('compta')      && peut_lire('compta'))      $ptRoutesImport[] = 'import_ecritures';
if (module_actif('evenements')  && peut_lire('evenements'))  $ptRoutesImport[] = 'import_evenements';
if (module_actif('booking')     && peut_lire('booking'))     $ptRoutesImport[] = 'import_structures';
if ($ptRoutesImport) {
    $ptGroupes['import'] = ['Importer', [$ptRoutesImport[0] => 'Importer'], $ptRoutesImport];
}

$ptGroupes['export'] = ['Exporter', ['export' => 'Exporter']];

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
?>
<div class="page-head-band">
<div class="page-head">
    <div class="page-head-title">
        <h1>Paramètres</h1>
    </div>
    <nav class="param-tabs">
        <?php foreach ($ptGroupes as $ptCle => $ptG): ?>
            <a href="?p=<?= array_key_first($ptG[1]) ?>" class="<?= $ptGroupeActif === $ptCle ? 'on' : '' ?>"><?= e($ptG[0]) ?></a>
        <?php endforeach; ?>
    </nav>
</div>
</div>
<?php if (count($ptSectionsActives) > 1): ?>
<nav class="param-subtabs">
    <?php foreach ($ptSectionsActives as $ptRoute => $ptLib): ?>
        <a href="?p=<?= $ptRoute ?>" class="<?= $ptCurParam === $ptRoute ? 'on' : '' ?>"><?= e($ptLib) ?></a>
    <?php endforeach; ?>
</nav>
<?php endif; ?>
