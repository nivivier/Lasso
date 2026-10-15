<?php
/** @var int $annee */ /** @var array $annees */ /** @var array $cols */
/** @var array $resultat */ /** @var array $sommesParAnnee */ /** @var array $totauxParAnnee */
/** @var array $plan */ /** @var array $patrimoine */ /** @var string $nomEmployeur */

$impression = true;
?>
<?php $impTitre = 'Bilan & résultat ' . (int) $annee . ($nomEmployeur !== '' ? ' — ' . $nomEmployeur : '');
require __DIR__ . '/_impression_debut.php'; ?>
        <div class="compta-print">
            <div class="cp-head">
                <?php $cpLogo = param_logo('clair'); ?>
                <?php if ($cpLogo !== ''): ?>
                <img src="<?= e($cpLogo) ?>" alt="" class="cp-logo">
                <?php endif; ?>
                <div>
                    <h1><?= $nomEmployeur !== '' ? e($nomEmployeur) : 'Comptabilité' ?></h1>
                    <div class="cp-sub">Bilan &amp; compte de résultat <?= (int) $annee ?></div>
                </div>
            </div>

            <?php require __DIR__ . '/_compta_bilan_body.php'; ?>

            <p class="cp-foot">Édité le <?= e(date('d.m.Y')) ?> · comptabilité de caisse</p>
        </div>

<?php require __DIR__ . '/_impression_fin.php'; ?>
