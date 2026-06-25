<?php
/** @var array $axe */ /** @var int $annee */ /** @var int $anneeRef */ /** @var array $annees */
/** @var array $cols */ /** @var array $plan */ /** @var array $sommesParAnnee */
/** @var array $totauxParAnnee */ /** @var array $ecritures */

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
    <div class="head-actions">
        <a href="?p=compta_ecritures&axe=<?= (int) $axe['id'] ?>&annee=<?= (int) $annee ?>" class="btn ghost btn-sm"><?= icon('pencil') ?> Écritures</a>
        <a href="?p=compta_analyse_axe_print&axe=<?= (int) $axe['id'] ?>&annee=<?= (int) $annee ?>" class="btn ghost" data-preview target="_blank" rel="noopener"><?= icon('eye') ?> Aperçu</a>
    </div>
</div>

<div class="section-head">
    <h2>Compte de résultat<?= $nbCols === 1 ? ' ' . (int) $anneeRef : '' ?></h2>
</div>
<div class="table-scroll bilan-card">
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

<?php if ($ecritures): ?>
<div class="section-head mt-28">
    <h2>Écritures</h2>
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
            <td class="texte-cell" title="<?= e($ecr['texte']) ?>"><?= e($ecr['texte']) ?></td>
            <td class="muted small"><?= e($ecr['cat_libelle']) ?></td>
            <td class="num <?= $neg ? 'montant-neg' : 'montant-pos' ?>"><?= chf((float) $ecr['montant']) ?></td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
<?php endif; ?>
