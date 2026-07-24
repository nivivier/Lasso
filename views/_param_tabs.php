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
$groupes = [];

// Application : administration (écriture cœur), voir index.php.
if (peut_ecrire('coeur')) {
    $groupes['application'] = ['Application', [
        'maj'                => 'Mises à jour',
        'apparence'          => 'Apparence',
        'parametres_modules' => 'Modules',
        'comptes'            => 'Utilisateurs',
        'diagnostic'         => 'Diagnostic du serveur',
    ]];
}

$groupes['employeur'] = ['Employeur', ['employeur' => 'Employeur']];
$groupes['emails']    = ['E-mails', ['emails' => 'E-mails']];

if (module_actif('salaires') && peut_lire('salaires')) {
    $groupes['taux'] = ['Taux', [
        'taux'          => 'Charges sociales et patronales',
        'taux_horaires' => 'Salaires horaires et unités',
    ]];
}

$catSections = ['parametres_pays' => 'Pays'];
if (module_actif('booking') && peut_lire('booking')) {
    $catSections['parametres_structures']       = 'Structures';
    $catSections['parametres_lieux_categories'] = 'Lieux';
}
$groupes['categories'] = ['Catégories', $catSections];

if (module_actif('evenements') && peut_lire('evenements')) {
    $groupes['evenements'] = ['Événements', ['parametres_evenements' => 'Événements']];
}

// Importer : un seul onglet couvrant plusieurs routes de traitement (selon les
// modules actifs) — le lien pointe vers la première, toutes comptent comme
// l'onglet actif (aliases).
$routesImport = [];
if (module_actif('salaires')    && peut_lire('salaires'))    $routesImport[] = 'import_fiches';
if (module_actif('facturation') && peut_lire('facturation')) $routesImport[] = 'import_factures';
if (module_actif('compta')      && peut_lire('compta'))      $routesImport[] = 'import_ecritures';
if (module_actif('evenements')  && peut_lire('evenements'))  $routesImport[] = 'import_evenements';
if (module_actif('booking')     && peut_lire('booking'))     $routesImport[] = 'import_structures';
if ($routesImport) {
    $groupes['import'] = ['Importer', [$routesImport[0] => 'Importer'], $routesImport];
}

$groupes['export'] = ['Exporter', ['export' => 'Exporter']];

$curParam = $_GET['p'] ?? '';

// Groupe actif : celui dont une section (ou un alias) correspond à la route.
$groupeActif = null;
foreach ($groupes as $cle => $g) {
    if (in_array($curParam, array_keys($g[1]), true) || in_array($curParam, $g[2] ?? [], true)) {
        $groupeActif = $cle;
        break;
    }
}
$sectionsActives = $groupeActif !== null ? $groupes[$groupeActif][1] : [];
?>
<div class="page-head-band">
<div class="page-head">
    <div class="page-head-title">
        <h1>Paramètres</h1>
    </div>
    <nav class="param-tabs">
        <?php foreach ($groupes as $cle => $g): ?>
            <a href="?p=<?= array_key_first($g[1]) ?>" class="<?= $groupeActif === $cle ? 'on' : '' ?>"><?= e($g[0]) ?></a>
        <?php endforeach; ?>
    </nav>
</div>
</div>
<?php if (count($sectionsActives) > 1): ?>
<nav class="param-subtabs">
    <?php foreach ($sectionsActives as $route => $lib): ?>
        <a href="?p=<?= $route ?>" class="<?= $curParam === $route ? 'on' : '' ?>"><?= e($lib) ?></a>
    <?php endforeach; ?>
</nav>
<?php endif; ?>
