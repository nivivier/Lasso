<?php /** @var array $emp */ /** @var int $annee */ /** @var array $fiches */ /** @var array $tot */ ?>
<?php $impTitre = 'Certificat ' . $emp['prenom'] . ' ' . $emp['nom'] . ' — ' . (int) $annee;
require __DIR__ . '/_impression_debut.php'; ?>
        <?php require __DIR__ . '/_certificat_body.php'; ?>

<?php require __DIR__ . '/_impression_fin.php'; ?>
