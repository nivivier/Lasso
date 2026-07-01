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
$tabs['export'] = 'Exporter';
if (module_actif('salaires')) {
    $tabs['import_fiches'] = 'Importer';
}
$tabs['comptes']             = 'Comptes';
$tabs['parametres_modules']  = 'Modules';
$tabs['maj']                 = 'Mises à jour';
$curParam = $_GET['p'] ?? '';
?>
<div class="page-head"><h1>Paramètres</h1></div>
<nav class="param-tabs">
    <?php foreach ($tabs as $p => $lib): ?>
        <a href="?p=<?= $p ?>" class="<?= $curParam === $p ? 'on' : '' ?>"><?= e($lib) ?></a>
    <?php endforeach; ?>
</nav>
