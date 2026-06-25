<?php
/** @var int $annee */ /** @var array $annees */ /** @var array $cols */
/** @var array $resultat */ /** @var array $sommesParAnnee */ /** @var array $totauxParAnnee */
/** @var array $plan */ /** @var array $patrimoine */ /** @var string $nomEmployeur */

$byParent = plan_enfants($plan);
$nbCols   = count($cols);

// Bloc statique (produits / charges) en arbre, une colonne par année.
$blocSens = function (string $sens, string $titre) use ($byParent, $sommesParAnnee, $cols, $nbCols): string {
    $pad = fn(int $p) => 'style="padding-left:' . (16 + $p * 18) . 'px"';
    $cellules = function (callable $val) use ($cols): string {
        $h = '';
        foreach ($cols as $a) {
            $h .= '<td class="num">' . chf($val((int) $a)) . '</td>';
        }
        return $h;
    };
    $rendre = function (array $row, int $prof) use (&$rendre, $byParent, $sommesParAnnee, $pad, $cellules): string {
        $id = (int) $row['id'];
        $enfants = $byParent[$id] ?? [];
        if ($enfants) {
            $h = '<tr class="cr-groupe"><td ' . $pad($prof) . '>' . e($row['libelle']) . '</td>'
               . $cellules(fn(int $a) => plan_sous_total($id, $byParent, $sommesParAnnee[$a] ?? [])) . '</tr>';
            foreach ($enfants as $child) {
                $h .= $rendre($child, $prof + 1);
            }
            return $h;
        }
        return '<tr class="cr-compte"><td ' . $pad($prof) . '>' . e($row['libelle']) . '</td>'
             . $cellules(fn(int $a) => (float) ($sommesParAnnee[$a][$id] ?? 0)) . '</tr>';
    };
    $h = '<tr class="cr-section"><th colspan="' . ($nbCols + 1) . '">' . e($titre) . '</th></tr>';
    foreach (array_filter($byParent[0] ?? [], fn($r) => $r['sens'] === $sens) as $r) {
        $h .= $rendre($r, 0);
    }
    return $h;
};
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title>Bilan & résultat <?= (int) $annee ?><?= $nomEmployeur !== '' ? ' — ' . e($nomEmployeur) : '' ?></title>
    <link rel="stylesheet" href="assets/app.css">
</head>
<body class="print-page">
    <div class="print-toolbar">
        <button onclick="window.print()">Imprimer / Enregistrer en PDF</button>
        <a href="?p=compta_bilan&annee=<?= (int) $annee ?>">Fermer</a>
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
                    <div class="cp-sub">Bilan &amp; compte de résultat — exercice <?= (int) $annee ?></div>
                </div>
            </div>

            <h2>État du patrimoine</h2>
            <table class="list">
                <thead>
                    <tr><th>Poste</th><?php foreach ($cols as $a): ?><th class="num">au 31.12.<?= (int) $a ?></th><?php endforeach; ?></tr>
                </thead>
                <tbody>
                    <?php foreach ($patrimoine as $p): ?>
                    <tr>
                        <td><?= e($p['libelle']) ?></td>
                        <?php foreach ($cols as $a): $v = $p['valeurs'][$a] ?? null; ?>
                            <td class="num"><?= $v === null ? '—' : chf((float) $v) ?></td>
                        <?php endforeach; ?>
                    </tr>
                    <?php endforeach; ?>
                    <tr class="cr-total">
                        <td>Total du patrimoine</td>
                        <?php foreach ($cols as $a): ?><td class="num"><?= chf(compta_total_patrimoine((int) $a, $patrimoine)) ?></td><?php endforeach; ?>
                    </tr>
                </tbody>
            </table>

            <h2>Compte de résultat<?= $nbCols > 1 ? '' : ' ' . (int) $annee ?></h2>
            <table class="list compta-cr">
                <?php if ($nbCols > 1): ?>
                <thead><tr><th>Catégorie</th><?php foreach ($cols as $a): ?><th class="num"><?= (int) $a ?></th><?php endforeach; ?></tr></thead>
                <?php endif; ?>
                <tbody>
                    <?= $blocSens('produit', 'Recettes') ?>
                    <?= compta_ligne_total('Total des recettes', 'produits', 'cr-total', $cols, $totauxParAnnee) ?>
                    <?= $blocSens('charge', 'Dépenses') ?>
                    <?= compta_ligne_total('Total des dépenses', 'charges', 'cr-total', $cols, $totauxParAnnee) ?>
                    <?= compta_ligne_total('Résultat de l\'année', 'resultat', 'cr-resultat', $cols, $totauxParAnnee) ?>
                </tbody>
            </table>

            <p class="cp-foot">Édité le <?= e(date('d.m.Y')) ?> · comptabilité de caisse</p>
        </div>
    </div>

</body>
</html>
