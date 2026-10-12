<?php /** @var array $f */ $impression = true; ?>
<?php $impTitre = 'Décompte ' . $f['employe_nom'] . ' — ' . mois_nom((int) $f['mois']) . ' ' . (int) $f['annee'];
require __DIR__ . '/_impression_debut.php'; ?>
        <?php require __DIR__ . '/_fiche_body.php'; ?>

<?php require __DIR__ . '/_impression_fin.php'; ?>
