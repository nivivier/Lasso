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

$pad = fn(int $p) => 'class="cr-noeud-pad" style="--depth:' . $p . '"';

$cellules = function (callable $val) use ($cols, $anneeRef, $nbCols): string {
    $h = '';
    $total = 0.0;
    foreach ($cols as $a) {
        $cls = (int) $a !== $anneeRef ? ' col-prec' : '';
        $v = $val((int) $a);
        $total += $v;
        $h .= '<td class="num' . $cls . '">' . chf($v) . '</td>';
    }
    if ($nbCols > 1) {
        $h .= '<td class="num total-col">' . chf($total) . '</td>';
    }
    return $h;
};

$ligneTotalAxe = function (string $libelle, string $cle, string $cls) use ($cols, $totauxParAnnee, $anneeRef, $nbCols): string {
    $h = '<tr class="' . $cls . '"><td>' . e($libelle) . '</td>';
    $total = 0.0;
    foreach ($cols as $a) {
        $precCls = (int) $a !== $anneeRef ? ' col-prec' : '';
        $v = (float) ($totauxParAnnee[(int) $a][$cle] ?? 0);
        $total += $v;
        $h .= '<td class="num' . $precCls . '">' . chf($v) . '</td>';
    }
    if ($nbCols > 1) {
        $h .= '<td class="num total-col">' . chf($total) . '</td>';
    }
    return $h . '</tr>';
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
    $h = '<tr class="cr-section"><th colspan="' . ($nbCols + 1 + ($nbCols > 1 ? 1 : 0)) . '">' . e($titre) . '</th></tr>';
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

            <table class="list compta-cr">
                <?php if ($nbCols > 1): ?>
                <thead>
                    <tr>
                        <th>Catégorie</th>
                        <?php foreach ($cols as $a): ?>
                            <th class="num<?= (int) $a !== $anneeRef ? ' col-prec' : '' ?>"><?= (int) $a ?></th>
                        <?php endforeach; ?>
                        <th class="num total-col">Total</th>
                    </tr>
                </thead>
                <?php endif; ?>
                <tbody>
                    <?= $blocSens('produit', 'Recettes') ?>
                    <?= $ligneTotalAxe('Total des recettes', 'produits', 'cr-total') ?>
                    <?= $blocSens('charge', 'Dépenses') ?>
                    <?= $ligneTotalAxe('Total des dépenses', 'charges', 'cr-total') ?>
                    <?= $ligneTotalAxe('Résultat', 'resultat', 'cr-resultat') ?>
                </tbody>
            </table>

            <p class="cp-foot">
                Édité le <?= e(date('d.m.Y')) ?> · comptabilité de caisse · écritures ventilées sur cet axe uniquement
            </p>
        </div>
    </div>

<script>document.addEventListener('keydown', e => { if (e.key === 'Escape') window.close(); });</script>
</body>
</html>
