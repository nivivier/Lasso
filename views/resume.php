<?php
/** @var int $annee */ /** @var array $annees */ /** @var array $employes */
/** @var int $employeId */ /** @var string $groupe */ /** @var array $buckets */
/** @var array $totaux */ /** @var array $champs */
/** @var array $retenues */ /** @var array $retCols */ /** @var int $retNb */ /** @var int $retAnnee */

$labels = [
    'brut'        => 'Brut',
    'charges_soc' => 'Charges sociales',
    'impots'      => 'Impôt à la source',
    'net'         => 'Net',
    'charges_pat' => 'Charges patronales',
    'cout_emp'    => 'Coût employeur',
];
$petits = ['charges_soc', 'impots', 'charges_pat'];
$retLabels = ['ocas' => 'OCAS', 'laa' => 'LAA', 'lpp' => 'LPP', 'impot' => 'Impôt à la source'];
$cls = function (string $c) use ($petits) {
    $k = 'num';
    if ($c === 'brut') $k .= ' col-brut';
    if ($c === 'cout_emp') $k .= ' col-cout';
    if ($c === 'net') $k .= ' strong';
    if (in_array($c, $petits, true)) $k .= ' col-petit';
    return $k;
};
$groupes = ['annee' => 'Année', 'semestre' => 'Semestre', 'trimestre' => 'Trimestre', 'mois' => 'Mois'];
?>
<div class="page-head"><h1>Cotisations</h1></div>

<div class="section-head">
    <h2 class="mt-0">Résumé complet</h2>
    <form method="get" class="annee-pick">
        <input type="hidden" name="p" value="resume">
        <select name="groupe" aria-label="Regroupement" onchange="this.form.submit()">
            <?php foreach ($groupes as $val => $lib): ?>
                <option value="<?= $val ?>" <?= $groupe === $val ? 'selected' : '' ?>><?= $lib ?></option>
            <?php endforeach; ?>
        </select>
        <select name="annee" class="year-select" aria-label="Année" onchange="this.form.submit()" <?= $groupe === 'annee' ? 'disabled' : '' ?>>
            <?php if ($groupe === 'annee'): ?>
                <option selected>Toutes</option>
            <?php else:
                $opts = array_unique(array_merge([$annee, (int) date('Y')], array_map('intval', $annees)));
                rsort($opts);
                foreach ($opts as $a): ?>
                    <option value="<?= $a ?>" <?= $a === $annee ? 'selected' : '' ?>><?= $a ?></option>
                <?php endforeach;
            endif; ?>
        </select>
        <select name="employe_id" aria-label="Employé" onchange="this.form.submit()">
            <option value="0">Tous les employés</option>
            <?php foreach ($employes as $emp): ?>
                <option value="<?= (int) $emp['id'] ?>" <?= $employeId === (int) $emp['id'] ? 'selected' : '' ?>>
                    <?= e($emp['prenom'] . ' ' . $emp['nom']) ?>
                </option>
            <?php endforeach; ?>
        </select>
    </form>
</div>

<?php if (!$buckets): ?>
    <p class="muted">Aucune fiche pour cette sélection.</p>
<?php else: ?>
<div class="table-scroll">
<table class="list resume">
    <colgroup>
        <col style="width:16%"><col style="width:8%">
        <?php foreach ($champs as $c): ?><col><?php endforeach; ?>
    </colgroup>
    <thead>
        <tr>
            <th>Période</th><th class="num">Fiches</th>
            <?php foreach ($champs as $c): ?><th class="num<?= in_array($c, $petits, true) ? ' col-petit' : '' ?>"><?= e($labels[$c]) ?></th><?php endforeach; ?>
        </tr>
    </thead>
    <tbody>
    <?php foreach ($buckets as $b): ?>
        <tr>
            <td><?= e($b['label']) ?></td>
            <td class="num"><?= (int) $b['nb'] ?></td>
            <?php foreach ($champs as $c): ?>
                <td class="<?= $cls($c) ?>"><?= chf((float) $b[$c]) ?></td>
            <?php endforeach; ?>
        </tr>
    <?php endforeach; ?>
    </tbody>
    <tfoot>
        <tr class="total-row">
            <td><?= $groupe === 'annee' ? 'Total général' : 'Total ' . $annee ?></td>
            <td class="num"><?= (int) $totaux['nb'] ?></td>
            <?php foreach ($champs as $c): ?>
                <td class="<?= $cls($c) ?> strong"><?= chf((float) $totaux[$c]) ?></td>
            <?php endforeach; ?>
        </tr>
    </tfoot>
</table>
</div>
<?php endif; ?>

<div class="section-head">
    <h2>Charges totales <?= info_tip('Montants en CHF, part employé + part patronale.') ?></h2>
    <form method="get" class="annee-pick">
        <input type="hidden" name="p" value="resume">
        <input type="hidden" name="groupe" value="<?= e($groupe) ?>">
        <input type="hidden" name="annee" value="<?= (int) $annee ?>">
        <input type="hidden" name="employe_id" value="<?= (int) $employeId ?>">
        <select name="ret_annee" aria-label="Année des charges totales" onchange="this.form.submit()">
            <?php
            $retOpts = array_unique(array_merge([$retAnnee, (int) date('Y')], array_map('intval', $annees)));
            rsort($retOpts);
            foreach ($retOpts as $a): ?>
                <option value="<?= $a ?>" <?= $a === $retAnnee ? 'selected' : '' ?>><?= $a ?></option>
            <?php endforeach; ?>
        </select>
    </form>
</div>
<?php if (!$retNb): ?>
    <p class="muted">Aucune fiche pour <?= (int) $retAnnee ?>.</p>
<?php else: ?>
<div class="table-scroll">
<table class="list resume">
    <colgroup>
        <col style="width:20%">
        <?php foreach ($retCols as $c): ?><col><?php endforeach; ?>
        <col>
    </colgroup>
    <thead>
        <tr>
            <th>Période</th>
            <?php foreach ($retCols as $c): ?><th class="num"><?= e($retLabels[$c]) ?><?php
                if ($c === 'ocas') echo info_tip('AVS/AI/APG, AC, A.mat, allocations familiales, frais, CPE, LFP.');
                elseif ($c === 'impot') echo info_tip('Retenue employé.');
            ?></th><?php endforeach; ?>
            <th class="num">Total</th>
        </tr>
    </thead>
    <tbody>
    <?php foreach ($retenues as $r): ?>
        <tr class="<?= $r['type'] === 'sous' ? 'subtotal-row' : ($r['type'] === 'total' ? 'total-row' : 'ret-trim') ?>">
            <td><?= e($r['label']) ?></td>
            <?php foreach ($retCols as $c): ?>
                <td class="num"><?= chf((float) $r['vals'][$c]) ?></td>
            <?php endforeach; ?>
            <td class="num col-total"><?= chf((float) array_sum($r['vals'])) ?></td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
</div>
<?php endif; ?>
