<?php
/** @var array $axe */ /** @var int $annee */ /** @var int $anneeRef */ /** @var array $annees */
/** @var array $cols */ /** @var array $plan */ /** @var array $sommesParAnnee */
/** @var array $totauxParAnnee */ /** @var string $nomEmployeur */

$impression = true;
// Le partiel recalcule $nbCols pour lui-même ; le titre ci-dessous en a
// besoin AVANT, d'où ces trois lignes gardées ici.
$nbCols        = count($cols);
$anneeRecente  = (int) ($cols[0] ?? 0);
$anneeAncienne = (int) ($cols[$nbCols - 1] ?? $anneeRecente);
$titreAnnee    = $nbCols > 1 ? $anneeAncienne . ' – ' . $anneeRecente : (string) $anneeRecente;
?>
<?php $impTitre = (string) $axe['libelle'] . ' ' . $titreAnnee . ($nomEmployeur !== '' ? ' — ' . $nomEmployeur : '');
require __DIR__ . '/_impression_debut.php'; ?>
        <div class="compta-print">
            <div class="cp-head">
                <?php $cpLogo = param_logo('clair'); ?>
                <?php if ($cpLogo !== ''): ?>
                <img src="<?= e($cpLogo) ?>" alt="" class="cp-logo">
                <?php endif; ?>
                <div>
                    <h1><?= $nomEmployeur !== '' ? e($nomEmployeur) : 'Comptabilité' ?></h1>
                    <div class="cp-sub"><?= e((string) $axe['libelle']) ?> <?= $titreAnnee ?></div>
                </div>
            </div>

            <?php require __DIR__ . '/_compta_analyse_axe_body.php'; ?>

            <p class="cp-foot">
                Édité le <?= e(date('d.m.Y')) ?> · comptabilité de caisse · écritures ventilées sur cet axe uniquement
            </p>
        </div>

<?php require __DIR__ . '/_impression_fin.php'; ?>
