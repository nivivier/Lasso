<?php
// Sous-onglets communs aux pages du mailing (ciblage booking). Pas de titre
// propre ici (le h1 "Booking" du bandeau de module au-dessus suffit — même
// principe que les sous-onglets de Paramètres, views/_param_tabs.php, qui ne
// répètent pas non plus "Paramètres").
$sousTabs = [
    'mailing'            => 'Aperçu',
    'mailing_campagne'   => 'Nouvelle campagne',
    'mailing_modeles'    => 'Modèles',
    'mailing_exclusions' => "Liste d'exclusion",
];
$curMailing = $_GET['p'] ?? '';
?>
<nav class="param-subtabs">
    <?php foreach ($sousTabs as $p => $lib): ?>
        <a href="?p=<?= $p ?>" class="<?= $curMailing === $p ? 'on' : '' ?>"><?= e($lib) ?></a>
    <?php endforeach; ?>
</nav>
