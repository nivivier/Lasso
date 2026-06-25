<?php
/** @var array $axe */ /** @var int $annee */ /** @var int $anneeRef */ /** @var array $annees */
/** @var array $cols */ /** @var array $plan */ /** @var array $sommesParAnnee */
/** @var array $totauxParAnnee */ /** @var string $nomEmployeur */

$byParent = plan_enfants($plan);
$nbCols   = count($cols);

// Vérifie si un nœud (feuille ou groupe) a des montants dans au moins une année.
$hasAmount = function (int $id) use (&$hasAmount, $byParent, $sommesParAnnee): bool {
    $enfants = $byParent[$id] ?? [];
    if ($enfants) {
        foreach ($enfants as $child) {
            if ($hasAmount((int) $child['id'])) return true;
        }
        return false;
    }
    foreach ($sommesParAnnee as $sums) {
        if (!empty($sums[$id])) return true;
    }
    return false;
};

$pad = fn(int $p) => 'style="padding-left:' . (16 + $p * 18) . 'px"';

$cellules = function (callable $val) use ($cols, $anneeRef): string {
    $h = '';
    foreach ($cols as $a) {
        $cls = (int) $a !== $anneeRef ? ' col-prec' : '';
        $h .= '<td class="num' . $cls . '">' . chf($val((int) $a)) . '</td>';
    }
    return $h;
};

$rendre = function (array $row, int $prof) use (&$rendre, $byParent, $sommesParAnnee, $pad, $cellules, $hasAmount): string {
    $id = (int) $row['id'];
    if (!$hasAmount($id)) return '';
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

$blocSens = function (string $sens, string $titre) use ($byParent, $nbCols, $rendre, $hasAmount): string {
    // N'affiche le bloc que si au moins un nœud de ce sens a des montants.
    $nodesRacine = array_filter($byParent[0] ?? [], fn($r) => $r['sens'] === $sens);
    $hasAny = false;
    foreach ($nodesRacine as $r) { if ($hasAmount((int) $r['id'])) { $hasAny = true; break; } }
    if (!$hasAny) return '';
    $h = '<tr class="cr-section"><th colspan="' . ($nbCols + 1) . '">' . e($titre) . '</th></tr>';
    foreach ($nodesRacine as $r) {
        $h .= $rendre($r, 0);
    }
    return $h;
};

$derniere = (int) ($cols[count($cols) - 1] ?? $anneeRef);
$titreAnnee = $nbCols > 1 ? $derniere . ' – ' . $anneeRef : (string) $anneeRef;
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title><?= e((string) $axe['libelle']) ?> <?= $titreAnnee ?><?= $nomEmployeur !== '' ? ' — ' . e($nomEmployeur) : '' ?></title>
    <link rel="stylesheet" href="assets/app.css">
</head>
<body class="print-page">
    <div class="print-toolbar">
        <button onclick="window.print()">Imprimer / Enregistrer en PDF</button>
        <a href="?p=compta_analyse">Fermer</a>
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
                    <div class="cp-sub">
                        <?= e((string) $axe['libelle']) ?>
                        <?php if ($axe['code']): ?><span class="muted"> · <?= e((string) $axe['code']) ?></span><?php endif; ?>
                        — <?= $nbCols > 1 ? 'exercices ' . $titreAnnee : 'exercice ' . $titreAnnee ?>
                    </div>
                </div>
            </div>

            <table class="list compta-cr" style="margin-top:16px">
                <?php if ($nbCols > 1): ?>
                <thead>
                    <tr>
                        <th>Catégorie</th>
                        <?php foreach ($cols as $a): ?>
                            <th class="num<?= (int) $a !== $anneeRef ? ' col-prec' : '' ?>"><?= (int) $a ?></th>
                        <?php endforeach; ?>
                    </tr>
                </thead>
                <?php endif; ?>
                <tbody>
                    <?= $blocSens('produit', 'Recettes') ?>
                    <?= compta_ligne_total('Total des recettes', 'produits', 'cr-total', $cols, $totauxParAnnee) ?>
                    <?= $blocSens('charge', 'Dépenses') ?>
                    <?= compta_ligne_total('Total des dépenses', 'charges', 'cr-total', $cols, $totauxParAnnee) ?>
                    <?= compta_ligne_total('Résultat', 'resultat', 'cr-resultat', $cols, $totauxParAnnee) ?>
                </tbody>
            </table>

            <p class="cp-foot">
                Édité le <?= e(date('d.m.Y')) ?> · comptabilité de caisse · écritures ventilées sur cet axe uniquement
            </p>
        </div>
    </div>
    <script>window.addEventListener('load', () => setTimeout(() => window.print(), 300));</script>
</body>
</html>
