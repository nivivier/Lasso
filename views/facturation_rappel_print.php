<?php /** @var array $facture */ $f = $facture; ?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title>Rappel — Facture <?= e($f['numero']) ?></title>
    <link rel="stylesheet" href="assets/app.css">
</head>
<body class="print-page">
    <div class="print-toolbar">
        <button onclick="window.print()"><?= icon('printer') ?> Imprimer / PDF</button>
    </div>
    <div class="sheet">
        <p><?= e((string) param('employeur_nom')) ?><br>
            <?= e((string) param('employeur_rue')) ?><br>
            <?= e((string) param('employeur_npa')) ?></p>

        <p style="margin-top:2em"><?= e($f['debiteur_nom']) ?><br>
            <?= e($f['adresse_rue']) ?><br>
            <?= e(trim($f['adresse_npa'] . ' ' . $f['adresse_localite'])) ?></p>

        <p style="margin-top:2em"><?= e(date('d.m.Y')) ?></p>

        <h2>Rappel de paiement — Facture <?= e($f['numero']) ?></h2>

        <p>Madame, Monsieur,</p>
        <p>
            Sauf erreur de notre part, la facture n<sup>o</sup> <?= e($f['numero']) ?> du
            <?= e(date('d.m.Y', strtotime($f['date_emission']))) ?>, d'un montant de
            <strong><?= chf((float) $f['montant_total']) ?> CHF</strong>, échue le
            <?= e(date('d.m.Y', strtotime($f['date_echeance']))) ?>, ne nous est pas encore parvenue.
        </p>
        <p>
            Nous vous remercions de bien vouloir procéder au règlement dans les meilleurs délais, ou de
            nous contacter si ce paiement a déjà été effectué.
        </p>
        <p>Avec nos meilleures salutations.</p>
    </div>

<script>document.addEventListener('keydown', e => { if (e.key === 'Escape') window.close(); });</script>
</body>
</html>
