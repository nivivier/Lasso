<?php /** @var array $f */ $impression = true; ?>
<!DOCTYPE html>
<html lang="fr" data-theme="clair">
<head>
    <meta charset="UTF-8">
    <title>Décompte <?= e($f['employe_nom']) ?> — <?= e(mois_nom((int) $f['mois'])) ?> <?= (int) $f['annee'] ?></title>
    <link rel="stylesheet" href="assets/app.css?v=<?= @filemtime(__DIR__ . '/../assets/app.css') ?: '1' ?>">
</head>
<body class="print-page">
    <div class="print-toolbar">
        <button data-print><?= icon('printer') ?> Imprimer / PDF</button>
    </div>
    <div class="sheet">
        <?php require __DIR__ . '/_fiche_body.php'; ?>
    </div>

<?php // « Imprimer / PDF » : data-print n'est traité que dans assets/app.js, et
      // une page d'impression ne charge pas tout le script de l'application pour
      // un appel. Sans cette ligne, le bouton ne fait rien. ?>
<script nonce="<?= e(csp_nonce()) ?>">
document.querySelector('[data-print]')?.addEventListener('click', () => window.print());
document.addEventListener('keydown', e => { if (e.key === 'Escape') window.close(); });
</script>
</body>
</html>
