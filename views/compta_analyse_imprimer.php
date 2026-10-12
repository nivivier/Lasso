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
            <?php else:
                $totProd = 0.0; $totChg = 0.0;
            ?>
            <table class="list compta-cr cr-flat">
                <thead>
                    <tr>
                        <th>Axe</th>
                        <th>Code</th>
                        <th class="num">Recettes</th>
                        <th class="num">Dépenses</th>
                        <th class="num">Résultat</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($ventilation as $v):
                    $res      = $v['resultat'];
                    $totProd += $v['produits'];
                    $totChg  += $v['charges'];
                ?>
                    <tr class="cr-groupe">
                        <td><?= e($v['libelle']) ?></td>
                        <td class="muted"><?= $v['code'] ? e($v['code']) : '' ?></td>
                        <td class="num"><?= $v['produits'] != 0 ? chf($v['produits']) : '—' ?></td>
                        <td class="num"><?= $v['charges']  != 0 ? chf($v['charges'])  : '—' ?></td>
                        <td class="num"><?= $res != 0 ? chf($res) : '—' ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
                <tfoot>
                    <tr class="cr-total">
                        <td>Total ventilé</td>
                        <td></td>
                        <td class="num"><?= chf($totProd) ?></td>
                        <td class="num"><?= chf($totChg) ?></td>
                        <td class="num"><?= chf($totProd + $totChg) ?></td>
                    </tr>
                </tfoot>
            </table>
            <?php endif; ?>
        </div>
<?php require __DIR__ . '/_impression_fin.php'; ?>
