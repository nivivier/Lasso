<?php /** @var array $p */ /** @var array $beneficiaire */
$jour = fn (?string $d): string => $d ? date('d.m.Y', strtotime($d)) : '';
// Une quittance constate de l'ARGENT REÇU : son montant se lit en positif,
// quel que soit le signe sous lequel il est rangé en comptabilité.
$montant = abs((float) $p['montant']);
?>
<!DOCTYPE html>
<html lang="fr" data-theme="clair">
<head>
    <meta charset="UTF-8">
    <title>Quittance <?= e((string) $p['numero']) ?></title>
    <link rel="stylesheet" href="assets/app.css?v=<?= @filemtime(__DIR__ . '/../assets/app.css') ?: '1' ?>">
</head>
<body class="print-page">
    <div class="print-toolbar">
        <button data-print><?= icon('printer') ?> Imprimer / PDF</button>
    </div>
    <div class="sheet">
        <p><?= e((string) param('employeur_nom')) ?><br>
            <?= e((string) param('employeur_rue')) ?><br>
            <?= e((string) param('employeur_npa')) ?></p>

        <?php if (trim($beneficiaire['nom']) !== ''): ?>
        <p style="margin-top:2em"><?= e($beneficiaire['nom']) ?>
            <?php if ($beneficiaire['adresse'] !== ''): ?><br><?= e($beneficiaire['adresse']) ?><?php endif; ?>
            <?php if ($beneficiaire['localite'] !== ''): ?><br><?= e($beneficiaire['localite']) ?><?php endif; ?>
        </p>
        <?php endif; ?>

        <p style="margin-top:2em"><?= e(date('d.m.Y')) ?></p>

        <h2>Quittance n<sup>o</sup> <?= e((string) $p['numero']) ?></h2>

        <?php // Une quittance CONSTATE, au passé. Une facture réclame, au futur :
              // c'est toute la différence, et elle doit s'entendre dès la
              // première phrase. ?>
        <p>
            Nous accusons réception, avec nos remerciements, de la somme de
            <strong><?= chf($montant) ?> CHF</strong>
            <?php if (trim($beneficiaire['nom']) !== ''): ?>versée par <?= e($beneficiaire['nom']) ?><?php endif; ?>
            <?php if ($p['date_payee'] !== ''): ?>le <?= e($jour((string) $p['date_payee'])) ?><?php endif; ?>.
        </p>

        <?php if (trim((string) $p['libelle']) !== ''): ?>
        <p><strong>Objet :</strong> <?= e((string) $p['libelle']) ?></p>
        <?php endif; ?>
        <?php if (trim((string) $p['notes']) !== ''): ?>
        <p><?= nl2br(e((string) $p['notes'])) ?></p>
        <?php endif; ?>

        <p>Avec nos meilleures salutations.</p>
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
