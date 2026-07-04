<?php
// Barre d'onglets commune aux pages de paramètres.
// L'onglet actif est déduit de la route courante (?p=…). Onglets propres à un
// module masqués si celui-ci est désactivé (lib/modules.php).
$tabs = [
    'employeur'     => 'Employeur',
    'emails'        => 'E-mails',
];
if (module_actif('salaires')) {
    $tabs['taux']          = 'Taux';
    $tabs['taux_horaires'] = 'Salaires horaires';
}
if (module_actif('evenements')) {
    $tabs['parametres_evenements'] = 'Événements';
}
$tabs['export'] = 'Exporter';

// Importer : une seule page couvrant fiches + factures + écritures (selon
// modules actifs), mais des routes de traitement distinctes (import_fiches /
// import_factures / import_ecritures, chacune avec sa propre logique et son
// propre résultat). Le lien pointe vers la première route existante ; toutes
// comptent comme le même onglet.
$routesImport = [];
if (module_actif('salaires'))    $routesImport[] = 'import_fiches';
if (module_actif('facturation')) $routesImport[] = 'import_factures';
if (module_actif('compta'))      $routesImport[] = 'import_ecritures';
if (module_actif('evenements'))  $routesImport[] = 'import_evenements';
if ($routesImport) {
    $tabs[$routesImport[0]] = ['Importer', $routesImport];
}

$tabs['comptes']             = 'Comptes';
$tabs['parametres_modules']  = 'Modules';
$tabs['maj']                 = 'Mises à jour';
$curParam = $_GET['p'] ?? '';
?>
<div class="page-head-band">
<div class="page-head">
    <div class="page-head-title">
        <h1>Paramètres</h1>
    </div>
    <nav class="param-tabs">
        <?php foreach ($tabs as $p => $lib):
            $label   = is_array($lib) ? $lib[0] : $lib;
            $aliases = is_array($lib) ? $lib[1] : [$p];
        ?>
            <a href="?p=<?= $p ?>" class="<?= in_array($curParam, $aliases, true) ? 'on' : '' ?>"><?= e($label) ?></a>
        <?php endforeach; ?>
    </nav>
</div>
</div>
