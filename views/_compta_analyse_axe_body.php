<?php
/** @var int $anneeRef */ /** @var array $cols */ /** @var array $plan */
/** @var array $sommesParAnnee */ /** @var array $totauxParAnnee */
// Compte de résultat d'un axe analytique, réutilisé à l'écran et à
// l'impression — même mécanique que _fiche_body.php et _compta_bilan_body.php.
//
// Les deux vues portaient la MÊME arborescence du plan comptable, recopiée :
// hasAmount() à l'identique, rendre() et cellules() à la mise en forme près,
// et 72 % des lignes utiles de la feuille imprimée se retrouvaient à l'écran.
// Deux copies d'un calcul de sous-totaux finissent par ne plus dire la même
// chose, et l'écart ne se verrait qu'en comparant une feuille à l'écran dont
// elle sort.
//
// $impression ne sépare que ce qui DOIT différer : l'indentation (18 px par
// niveau contre 22), le conteneur de défilement qu'une feuille n'a pas, les
// rangées d'aération, et le bloc vide — à l'écran un tiret dit « rien ici »,
// sur le papier on ne l'imprime pas.
/** @var bool $impression */
$impression = $impression ?? false;

$byParent = plan_enfants($plan);
$nbCols   = count($cols);
// Colonne « Total » en plus dès qu'on compare plusieurs années.
$colspan  = $nbCols + 1 + ($nbCols > 1 ? 1 : 0);

// Un nœud (feuille ou groupe) a-t-il des montants dans au moins une année ?
// Ce qui n'en a dans aucune ne figure pas : un axe ne touche qu'une poignée de
// catégories, et le plan entier noierait les trois qui comptent.
$hasAmount = function (int $id) use (&$hasAmount, $byParent, $sommesParAnnee): bool {
    $enfants = $byParent[$id] ?? [];
    if ($enfants) {
        foreach ($enfants as $child) {
            if ($hasAmount((int) $child['id'])) {
                return true;
            }
        }
        return false;
    }
    foreach ($sommesParAnnee as $sums) {
        if (!empty($sums[$id])) {
            return true;
        }
    }
    return false;
};

$classePad = $impression ? 'cr-noeud-pad' : 'bilan-noeud-pad';
$pad = fn (int $p) => 'class="' . $classePad . '" style="--depth:' . $p . '"';

// Les cellules d'une rangée : une par année, puis le total quand on compare.
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

// Une rangée de total, lue dans $totauxParAnnee plutôt que dans l'arbre.
$ligneTotale = function (string $libelle, string $cle, string $cls) use ($cols, $totauxParAnnee, $anneeRef, $nbCols): string {
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
    if (!$hasAmount($id)) {
        return '';
    }
    $enfants = $byParent[$id] ?? [];
    if ($enfants) {
        $h = '<tr class="cr-groupe"><td ' . $pad($prof) . '>' . e($row['libelle']) . '</td>'
           . $cellules(fn (int $a) => plan_sous_total($id, $byParent, $sommesParAnnee[$a] ?? [])) . '</tr>';
        foreach ($enfants as $child) {
            $h .= $rendre($child, $prof + 1);
        }
        return $h;
    }
    return '<tr class="cr-compte"><td ' . $pad($prof) . '>' . e($row['libelle']) . '</td>'
         . $cellules(fn (int $a) => (float) ($sommesParAnnee[$a][$id] ?? 0)) . '</tr>';
};

$blocSens = function (string $sens, string $titre) use ($byParent, $colspan, $rendre, $hasAmount, $impression): string {
    $nodesRacine = array_filter($byParent[0] ?? [], fn ($r) => $r['sens'] === $sens);
    $hasAny = false;
    foreach ($nodesRacine as $r) {
        if ($hasAmount((int) $r['id'])) {
            $hasAny = true;
            break;
        }
    }
    // Rien dans ce sens : l'écran garde le titre et pose un tiret — l'absence
    // de recettes est une information. Le papier n'imprime pas une section vide.
    if (!$hasAny) {
        return $impression ? ''
            : '<tr class="cr-section"><th colspan="' . $colspan . '">' . e($titre) . '</th></tr>'
              . '<tr><td colspan="' . $colspan . '" class="muted small" style="padding-left:16px">—</td></tr>';
    }
    $h = '<tr class="cr-section"><th colspan="' . $colspan . '">' . e($titre) . '</th></tr>';
    foreach ($nodesRacine as $r) {
        $h .= $rendre($r, 0);
    }
    return $h;
};

$ouvrirTable = fn (): string => $impression ? '' : '<div class="table-scroll">';
$fermerTable = fn (): string => $impression ? '' : '</div>';
$espaceur = $impression ? '' : '<tr class="cr-spacer"><td colspan="' . $colspan . '"></td></tr>';
?>
<?= $ouvrirTable() ?>
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
        <?= $ligneTotale('Total des recettes', 'produits', 'cr-total') ?>
        <?= $espaceur ?>
        <?= $blocSens('charge', 'Dépenses') ?>
        <?= $ligneTotale('Total des dépenses', 'charges', 'cr-total') ?>
        <?= $espaceur ?>
        <?= $ligneTotale('Résultat', 'resultat', 'cr-resultat') ?>
    </tbody>
</table>
<?= $fermerTable() ?>
