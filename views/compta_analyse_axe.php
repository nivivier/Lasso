<?php
/** @var array $axe */ /** @var int $annee */ /** @var int $anneeRef */ /** @var array $annees */
/** @var array $cols */ /** @var array $plan */ /** @var array $sommesParAnnee */
/** @var array $totauxParAnnee */ /** @var array $chargesParAnnee */ /** @var array $ecritures */

$byParent = plan_enfants($plan);
$nbCols   = count($cols);

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

$pad = fn(int $p) => 'class="bilan-noeud-pad" style="--depth:' . $p . '"';

$cellules = function (callable $val) use ($cols, $anneeRef, $nbCols): string {
    $h = ''; $total = 0.0;
    foreach ($cols as $a) {
        $cls = (int) $a !== $anneeRef ? ' col-prec' : '';
        $v = $val((int) $a);
        $total += $v;
        $h .= '<td class="num' . $cls . '">' . chf($v) . '</td>';
    }
    if ($nbCols > 1) $h .= '<td class="num total-col">' . chf($total) . '</td>';
    return $h;
};

$ligneTotale = function (string $libelle, string $cle, string $cls) use ($cols, $totauxParAnnee, $anneeRef, $nbCols): string {
    $h = '<tr class="' . $cls . '"><td>' . e($libelle) . '</td>';
    $total = 0.0;
    foreach ($cols as $a) {
        $precCls = (int) $a !== $anneeRef ? ' col-prec' : '';
        $v = (float) ($totauxParAnnee[(int) $a][$cle] ?? 0);
        $total += $v;
        $h .= '<td class="num' . $precCls . '">' . chf($v) . '</td>';
    }
    if ($nbCols > 1) $h .= '<td class="num total-col">' . chf($total) . '</td>';
    return $h . '</tr>';
};

$rendre = function (array $row, int $prof) use (&$rendre, $byParent, $sommesParAnnee, $pad, $cellules, $hasAmount): string {
    $id = (int) $row['id'];
    if (!$hasAmount($id)) return '';
    $enfants = $byParent[$id] ?? [];
    if ($enfants) {
        $h = '<tr class="cr-groupe"><td ' . $pad($prof) . '>' . e($row['libelle']) . '</td>'
           . $cellules(fn(int $a) => plan_sous_total($id, $byParent, $sommesParAnnee[$a] ?? [])) . '</tr>';
        foreach ($enfants as $child) $h .= $rendre($child, $prof + 1);
        return $h;
    }
    return '<tr class="cr-compte"><td ' . $pad($prof) . '>' . e($row['libelle']) . '</td>'
         . $cellules(fn(int $a) => (float) ($sommesParAnnee[$a][$id] ?? 0)) . '</tr>';
};

$blocSens = function (string $sens, string $titre) use ($byParent, $nbCols, $rendre, $hasAmount): string {
    $nodesRacine = array_filter($byParent[0] ?? [], fn($r) => $r['sens'] === $sens);
    $hasAny = false;
    foreach ($nodesRacine as $r) { if ($hasAmount((int) $r['id'])) { $hasAny = true; break; } }
    if (!$hasAny) return '';
    $h = '<tr class="cr-section"><th colspan="' . ($nbCols + 1 + ($nbCols > 1 ? 1 : 0)) . '">' . e($titre) . '</th></tr>';
    foreach ($nodesRacine as $r) $h .= $rendre($r, 0);
    return $h;
};
?>
<?= lien_retour('?p=compta_analyse&annee=' . (int) $annee, 'Comptabilité analytique') ?>
<div class="page-head">
    <div class="page-head-title">
        <h1><?= e((string) $axe['libelle']) ?><?php if ($axe['code']): ?> <span class="muted small"><?= e((string) $axe['code']) ?></span><?php endif; ?></h1>
        <form method="get">
            <input type="hidden" name="p" value="compta_analyse_axe">
            <input type="hidden" name="axe" value="<?= (int) $axe['id'] ?>">
            <select name="annee" class="inline-year-select" onchange="this.form.submit()">
                <option value="0" <?= $annee === 0 ? 'selected' : '' ?>>Toutes</option>
                <?php foreach ($annees as $a): ?>
                    <option value="<?= (int) $a ?>" <?= (int) $a === $annee ? 'selected' : '' ?>><?= (int) $a ?></option>
                <?php endforeach; ?>
            </select>
        </form>
    </div>
</div>

<div class="section-head">
    <h2>Compte de résultat<?= $annee !== 0 && $nbCols === 1 ? ' ' . (int) $anneeRef : '' ?></h2>
	<a href="?p=compta_analyse_axe_print&axe=<?= (int) $axe['id'] ?>&annee=<?= (int) $annee ?>" class="btn ghost btn-sm ml-auto" data-preview target="_blank" rel="noopener"><?= icon('eye') ?> Aperçu</a>
</div>

<div class="table-scroll">
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
        <?= $blocSens('charge', 'Dépenses') ?>
        <?= $ligneTotale('Total des dépenses', 'charges', 'cr-total') ?>
        <?= $ligneTotale('Résultat', 'resultat', 'cr-resultat') ?>
    </tbody>
</table>
</div>

<?php if ($chargesParAnnee):
    $colsChar    = array_keys($chargesParAnnee);
    $nbChar      = count($colsChar);
    $hasAmat     = !empty(array_filter($chargesParAnnee, fn($c) => abs($c['ded_amat']) > 0.001));
    $hasIs       = !empty(array_filter($chargesParAnnee, fn($c) => abs($c['ded_impot_source']) > 0.001));
    $cellsChar   = function (string $field) use ($chargesParAnnee, $colsChar, $anneeRef, $nbChar): string {
        $h = ''; $tot = 0.0;
        foreach ($colsChar as $a) {
            $v = (float) ($chargesParAnnee[$a][$field] ?? 0);
            $tot += $v;
            $cls = $nbChar > 1 && $a !== $anneeRef ? ' col-prec' : '';
            $h .= '<td class="num' . $cls . '">' . chf($v) . '</td>';
        }
        if ($nbChar > 1) $h .= '<td class="num total-col">' . chf($tot) . '</td>';
        return $h;
    };
    $thChar = function () use ($colsChar, $anneeRef, $nbChar): string {
        if ($nbChar <= 1) return '';
        $h = '';
        foreach ($colsChar as $a) {
            $h .= '<th class="num' . ($a !== $anneeRef ? ' col-prec' : '') . '">' . (int) $a . '</th>';
        }
        return '<thead><tr><th></th>' . $h . '<th class="num total-col">Total</th></tr></thead>';
    };
?>
<div class="section-head"><h2>Charges sociales prévues<?= $annee !== 0 && $nbChar === 1 ? ' ' . (int) $colsChar[0] : '' ?></h2></div>
<div class="table-scroll">
<table class="list compta-cr">
    <?= $thChar() ?>
    <tbody>
        <tr class="grand-total brut-line"><td>Salaire brut (prorata)</td><?= $cellsChar('salaire_brut') ?></tr>
        <tr class="cr-section"><th colspan="<?= $nbChar + 1 + ($nbChar > 1 ? 1 : 0) ?>">Déductions employé</th></tr>
        <tr class="cr-compte"><td class="bilan-noeud-pad" style="--depth:0">AVS / AI / APG</td><?= $cellsChar('ded_avs') ?></tr>
        <tr class="cr-compte"><td class="bilan-noeud-pad" style="--depth:0">AC</td><?= $cellsChar('ded_ac') ?></tr>
        <?php if ($hasAmat): ?>
        <tr class="cr-compte"><td class="bilan-noeud-pad" style="--depth:0">Assurance maternité</td><?= $cellsChar('ded_amat') ?></tr>
        <?php endif; ?>
        <tr class="cr-compte"><td class="bilan-noeud-pad" style="--depth:0">LAA</td><?= $cellsChar('ded_laa') ?></tr>
        <tr class="cr-compte"><td class="bilan-noeud-pad" style="--depth:0">LPP</td><?= $cellsChar('ded_lpp') ?></tr>
        <?php if ($hasIs): ?>
        <tr class="cr-compte"><td class="bilan-noeud-pad" style="--depth:0">Impôt à la source</td><?= $cellsChar('ded_impot_source') ?></tr>
        <?php endif; ?>
        <tr class="cr-total"><td>Total déductions</td><?= $cellsChar('total_deductions') ?></tr>
        <tr><td>Salaire net (prorata)</td><?= $cellsChar('salaire_net') ?></tr>
        <tr class="cr-section"><th colspan="<?= $nbChar + 1 + ($nbChar > 1 ? 1 : 0) ?>">Charges patronales</th></tr>
        <tr class="cr-compte"><td class="bilan-noeud-pad" style="--depth:0">AVS / AI / APG + AC + A.mat + AF</td>
            <?php
            $cellsOcasEmp = function () use ($chargesParAnnee, $colsChar, $anneeRef, $nbChar): string {
                $h = ''; $tot = 0.0;
                foreach ($colsChar as $a) {
                    $c = $chargesParAnnee[$a];
                    $v = $c['emp_avs'] + $c['emp_ac'] + $c['emp_amat'] + $c['emp_af'];
                    $tot += $v;
                    $cls = $nbChar > 1 && $a !== $anneeRef ? ' col-prec' : '';
                    $h .= '<td class="num' . $cls . '">' . chf($v) . '</td>';
                }
                if ($nbChar > 1) $h .= '<td class="num total-col">' . chf($tot) . '</td>';
                return $h;
            };
            echo $cellsOcasEmp();
            ?>
        </tr>
        <tr class="cr-compte"><td class="bilan-noeud-pad" style="--depth:0">LAA patronale</td><?= $cellsChar('emp_laa') ?></tr>
        <tr class="cr-compte"><td class="bilan-noeud-pad" style="--depth:0">LPP patronale</td><?= $cellsChar('emp_lpp') ?></tr>
        <tr class="cr-total"><td>Total charges patronales</td><?= $cellsChar('total_charges_emp') ?></tr>
        <tr class="grand-total"><td>Coût total employeur</td><?= $cellsChar('cout_total_emp') ?></tr>
    </tbody>
</table>
</div>
<?php endif; ?>

<?php if ($ecritures): ?>
<div class="section-head">
    <h2>Écritures</h2>
    <a href="?p=compta_ecritures&axe=<?= (int) $axe['id'] ?>&annee=<?= (int) $annee ?>" class="btn ghost btn-sm ml-auto"><?= icon('pencil') ?> Modifier</a>
</div>

<table class="list compta-lettrage">
    <thead>
        <tr>
            <th>Date</th>
            <th>Compte</th>
            <th>Texte</th>
            <th>Catégorie</th>
            <th class="num">Montant</th>
        </tr>
    </thead>
    <tbody>
    <?php foreach ($ecritures as $ecr):
        $neg = (float) $ecr['montant'] < 0;
    ?>
        <tr>
            <td class="nowrap"><?= e(date('d.m.Y', strtotime((string) $ecr['date_op']))) ?></td>
            <td class="muted small nowrap"><?= e($ecr['compte_libelle']) ?></td>
            <td class="texte-cell" title="<?= e($ecr['texte']) ?>" data-summary="<?= e(resumer_texte_postfinance($ecr['texte'])) ?>"><?= e(resumer_texte_postfinance($ecr['texte'])) ?></td>
            <td class="muted small"><?= e($ecr['cat_libelle']) ?></td>
            <td class="num <?= $neg ? 'montant-neg' : 'montant-pos' ?>"><?= chf((float) $ecr['montant']) ?></td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
<?php endif; ?>
