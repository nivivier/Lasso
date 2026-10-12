<?php /** @var array $p */ /** @var array $beneficiaire */
$jour = fn (?string $d): string => $d ? date('d.m.Y', strtotime($d)) : '';
// Une quittance constate de l'ARGENT REÇU : son montant se lit en positif,
// quel que soit le signe sous lequel il est rangé en comptabilité.
$montant = abs((float) $p['montant']);
?>
<?php $impTitre = 'Quittance ' . (string) $p['numero'];
require __DIR__ . '/_impression_debut.php'; ?>
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

<?php require __DIR__ . '/_impression_fin.php'; ?>
