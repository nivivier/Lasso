<?php
// Sous-onglets communs aux pages du mailing (ciblage booking).
$sousTabs = [
    'mailing'            => 'Aperçu',
    'mailing_campagne'   => 'Nouvelle campagne',
    'mailing_modeles'    => 'Modèles',
    'mailing_exclusions' => "Liste d'exclusion",
];
$curMailing = $_GET['p'] ?? '';
?>
<div class="page-head">
    <h1>Mailing</h1>
</div>
<nav class="param-subtabs">
    <?php foreach ($sousTabs as $p => $lib): ?>
        <a href="?p=<?= $p ?>" class="<?= $curMailing === $p ? 'on' : '' ?>"><?= e($lib) ?></a>
    <?php endforeach; ?>
</nav>
