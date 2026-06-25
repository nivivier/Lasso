<?php /** @var array $emp */ /** @var int $annee */ /** @var array $fiches */ /** @var array $tot */ ?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title>Certificat <?= e($emp['prenom'] . ' ' . $emp['nom']) ?> — <?= (int) $annee ?></title>
    <link rel="stylesheet" href="assets/app.css">
</head>
<body class="print-page">
    <div class="print-toolbar">
        <button onclick="window.print()">Imprimer / Enregistrer en PDF</button>
        <a href="?p=certificat&employe_id=<?= (int) $emp['id'] ?>&annee=<?= (int) $annee ?>">Fermer</a>
    </div>
    <div class="sheet">
        <?php require __DIR__ . '/_certificat_body.php'; ?>
    </div>

</body>
</html>
