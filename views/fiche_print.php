<?php /** @var array $f */ $impression = true; ?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title>Décompte <?= e($f['employe_nom']) ?> — <?= e(mois_nom((int) $f['mois'])) ?> <?= (int) $f['annee'] ?></title>
    <link rel="stylesheet" href="assets/app.css">
</head>
<body class="print-page">
    <div class="print-toolbar">
        <button onclick="window.print()">Imprimer / Enregistrer en PDF</button>
        <a href="?p=fiche&id=<?= (int) $f['id'] ?>">Fermer</a>
    </div>
    <div class="sheet">
        <?php require __DIR__ . '/_fiche_body.php'; ?>
    </div>
    <script>window.addEventListener('load', () => setTimeout(() => window.print(), 300));</script>
</body>
</html>
