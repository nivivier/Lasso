<?php
/** @var int $annee */ /** @var array $axes */ /** @var array $ventilation */
/** @var array $detailParAxe */ /** @var string $nomEmployeur */
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title>Comptabilité analytique <?= $annee ? (int) $annee : 'toutes les années' ?><?= $nomEmployeur !== '' ? ' — ' . e($nomEmployeur) : '' ?></title>
    <link rel="stylesheet" href="assets/app.css">
</head>
<body class="print-page">
    <div class="print-toolbar">
        <button onclick="window.print()">Imprimer / Enregistrer en PDF</button>
        <a href="?p=compta_analyse&annee=<?= (int) $annee ?>">Fermer</a>
    </div>
    <div class="sheet">
        <div class="compta-print">
            <div class="cp-head">
                <?php $cpLogo = param_logo('clair'); ?>
                <?php if ($cpLogo !== ''): ?>
                <img src="<?= e($cpLogo) ?>" alt="" class="cp-logo">
                <?php endif; ?>
                <div>
                    <h1><?= $nomEmployeur !== '' ? e($nomEmployeur) : 'Comptabilité' ?></h1>
                    <p>Comptabilité analytique — <?= $annee ? 'Exercice ' . (int) $annee : 'Toutes les années' ?></p>
                </div>
            </div>

            <?php if (!$ventilation): ?>
            <p class="muted small">Aucune écriture ventilée pour <?= (int) $annee ?>.</p>
            <?php else:
                $totProd = 0.0; $totChg = 0.0;
            ?>
            <table class="list compta-cr" style="margin-top:16px">
                <thead>
                    <tr>
                        <th>Axe</th>
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
                    $detail   = $detailParAxe[(int) $v['id']] ?? [];
                ?>
                    <tr class="cr-groupe">
                        <td><?= e($v['libelle']) ?><?php if ($v['code']): ?> <span class="muted small">· <?= e($v['code']) ?></span><?php endif; ?></td>
                        <td class="num"><?= $v['produits'] != 0 ? chf($v['produits']) : '—' ?></td>
                        <td class="num"><?= $v['charges']  != 0 ? chf($v['charges'])  : '—' ?></td>
                        <td class="num"><?= $res != 0 ? chf($res) : '—' ?></td>
                    </tr>
                    <?php
                    $curSens = null;
                    foreach ($detail as $pid => $cat):
                        if ($cat['sens'] !== $curSens):
                            $curSens = $cat['sens'];
                    ?>
                        <tr><td colspan="4" style="padding:4px 12px 0 34px; font-size:10px; color:#888; text-transform:uppercase; letter-spacing:.05em; font-weight:600">
                            <?= $curSens === 'produit' ? 'Recettes' : 'Dépenses' ?>
                        </td></tr>
                    <?php endif; ?>
                        <tr class="cr-compte">
                            <td style="padding-left:44px"><?= e($cat['libelle']) ?></td>
                            <td class="num"><?= $cat['sens'] === 'produit' && $cat['montant'] != 0 ? chf($cat['montant']) : '—' ?></td>
                            <td class="num"><?= $cat['sens'] === 'charge'  && $cat['montant'] != 0 ? chf($cat['montant']) : '—' ?></td>
                            <td class="num"></td>
                        </tr>
                        <?php foreach ($cat['lignes'] as $l): ?>
                        <tr style="font-size:9px; color:#666">
                            <td style="padding-left:56px"><?= e(date('d.m.Y', strtotime((string) $l['date_op']))) ?> · <?= e($l['texte']) ?></td>
                            <td class="num" colspan="3"><?= chf((float) $l['montant']) ?></td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endforeach; ?>
                <?php endforeach; ?>
                </tbody>
                <tfoot>
                    <tr class="cr-total">
                        <td>Total ventilé</td>
                        <td class="num"><?= chf($totProd) ?></td>
                        <td class="num"><?= chf($totChg) ?></td>
                        <td class="num"><?= chf($totProd + $totChg) ?></td>
                    </tr>
                </tfoot>
            </table>
            <?php endif; ?>
        </div>
    </div>
<script>document.addEventListener('keydown', e => { if (e.key === 'Escape') window.close(); });</script>
</body>
</html>
