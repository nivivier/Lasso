<?php
// Barre d'onglets commune aux pages de paramètres.
// L'onglet actif est déduit de la route courante (?p=…).
$tabs = [
    'employeur'     => 'Employeur',
    'emails'        => 'E-mails',
    'taux'          => 'Taux',
    'taux_horaires' => 'Salaires horaires',
    'export'        => 'Exporter',
    'import_fiches' => 'Importer',
    'comptes'       => 'Comptes',
    'maj'           => 'Mises à jour',
];
$curParam = $_GET['p'] ?? '';
?>
<div class="page-head"><h1>Paramètres</h1></div>
<nav class="param-tabs">
    <?php foreach ($tabs as $p => $lib): ?>
        <a href="?p=<?= $p ?>" class="<?= $curParam === $p ? 'on' : '' ?>"><?= e($lib) ?></a>
    <?php endforeach; ?>
</nav>
