<?php
/** @var int $annee */ /** @var array $axes */ /** @var array $ventilation */
/** @var array $detailParAxe */ /** @var string $nomEmployeur */
?>
<?php $impTitre = 'Comptabilité analytique ' . ($annee ? (int) $annee : 'toutes les années')
    . ($nomEmployeur !== '' ? ' — ' . $nomEmployeur : '');
require __DIR__ . '/_impression_debut.php'; ?>
        <div class="compta-print">
            <div class="cp-head">
                <?php $cpLogo = param_logo('clair'); ?>
                <?php if ($cpLogo !== ''): ?>
                <img src="<?= e($cpLogo) ?>" alt="" class="cp-logo">
                <?php endif; ?>
                <div>
                    <h1><?= $nomEmployeur !== '' ? e($nomEmployeur) : 'Comptabilité' ?></h1>
                    <div class="cp-sub">Comptabilité analytique <?= $annee ? (int) $annee : '— toutes les années' ?></div>
                </div>
            </div>

            <?php if (!$ventilation): ?>
            <p class="muted small">Aucune écriture ventilée<?= $annee ? ' pour ' . (int) $annee : '' ?>.</p>
            <?php else: $impression = true; ?>
            <?php require __DIR__ . '/_compta_analyse_body.php'; ?>
            <?php endif; ?>
        </div>
<?php require __DIR__ . '/_impression_fin.php'; ?>
