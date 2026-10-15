<?php
/** @var array $axe */ /** @var int $annee */ /** @var int $anneeRef */ /** @var array $annees */
/** @var array $cols */ /** @var array $plan */ /** @var array $sommesParAnnee */
/** @var array $totauxParAnnee */ /** @var array $chargesParAnnee */ /** @var array $ecritures */

// Le partiel recalcule $nbCols pour lui-même ; le <h2> plus bas en a besoin
// AVANT, d'où cette ligne gardée ici.
$nbCols = count($cols);
?>
<?php require __DIR__ . '/_module_tabs.php'; ?>
<?php require __DIR__ . '/_page_head_band.php'; ?>

<div class="module-content"><div class="module-content-inner">
<?= lien_retour('?p=compta_analyse&annee=' . (int) $annee, 'Comptabilité analytique') ?>
<div class="page-head">
    <div class="page-head-title">
        <h1><?= e((string) $axe['libelle']) ?><?php if ($axe['code']): ?> <span class="muted small"><?= e((string) $axe['code']) ?></span><?php endif; ?></h1>
        <form method="get">
            <input type="hidden" name="p" value="compta_analyse_axe">
            <input type="hidden" name="axe" value="<?= (int) $axe['id'] ?>">
            <select name="annee" class="inline-year-select" data-submit-on-change>
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
	<a href="?p=compta_analyse_axe_imprimer&axe=<?= (int) $axe['id'] ?>&annee=<?= (int) $annee ?>" class="btn ghost ml-auto" data-preview target="_blank" rel="noopener"><?= icon('eye') ?> Aperçu</a>
</div>

<?php require __DIR__ . '/_compta_analyse_axe_body.php'; ?>

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
    // Total déductions employé + charges patronales (distinct du « coût total
    // employeur », qui part du brut plutôt que des seules charges sociales).
    $cellsCharTotal = function () use ($chargesParAnnee, $colsChar, $anneeRef, $nbChar): string {
        $h = ''; $tot = 0.0;
        foreach ($colsChar as $a) {
            $v = (float) ($chargesParAnnee[$a]['total_deductions'] ?? 0) + (float) ($chargesParAnnee[$a]['total_charges_emp'] ?? 0);
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
        <tr class="cr-compte"><td>AVS / AI / APG</td><?= $cellsChar('ded_avs') ?></tr>
        <tr class="cr-compte"><td>AC</td><?= $cellsChar('ded_ac') ?></tr>
        <?php if ($hasAmat): ?>
        <tr class="cr-compte"><td>Assurance maternité</td><?= $cellsChar('ded_amat') ?></tr>
        <?php endif; ?>
        <tr class="cr-compte"><td>LAA</td><?= $cellsChar('ded_laa') ?></tr>
        <tr class="cr-compte"><td>LPP</td><?= $cellsChar('ded_lpp') ?></tr>
        <?php if ($hasIs): ?>
        <tr class="cr-compte"><td>Impôt à la source</td><?= $cellsChar('ded_impot_source') ?></tr>
        <?php endif; ?>
        <tr class="cr-total"><td>Total déductions</td><?= $cellsChar('total_deductions') ?></tr>
        <tr><td>Salaire net (prorata)</td><?= $cellsChar('salaire_net') ?></tr>
        <tr class="cr-section"><th colspan="<?= $nbChar + 1 + ($nbChar > 1 ? 1 : 0) ?>">Charges patronales</th></tr>
        <tr class="cr-compte"><td>OCAS + AF (patronale)</td><?= $cellsChar('emp_ocas') ?></tr>
        <tr class="cr-compte"><td>LAA patronale</td><?= $cellsChar('emp_laa') ?></tr>
        <tr class="cr-compte"><td>LPP patronale</td><?= $cellsChar('emp_lpp') ?></tr>
        <tr class="cr-total"><td>Total charges patronales</td><?= $cellsChar('total_charges_emp') ?></tr>
        <tr class="grand-total"><td>Coût total employeur</td><?= $cellsChar('cout_total_emp') ?></tr>
        <tr class="grand-total"><td>Total des charges (salariales + patronales)</td><?= $cellsCharTotal() ?></tr>
    </tbody>
</table>
</div>
<?php endif; ?>

<?php if ($ecritures): ?>
<div class="section-head">
    <h2>Écritures</h2>
    <?= champ_recherche(['id' => 'axe-ecritures-search', 'classe' => 'ml-auto']) ?>
    <a href="?p=compta_ecritures&axe=<?= (int) $axe['id'] ?>&annee=<?= (int) $annee ?>" class="btn ghost"><?= icon('pencil') ?> Modifier</a>
</div>

<div class="table-scroll">
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
            <td class="texte-cell" title="<?= e($ecr['texte']) ?>"><span class="texte-cell-txt" data-summary="<?= e(resumer_texte_postfinance($ecr['texte'])) ?>"><?= e(resumer_texte_postfinance($ecr['texte'])) ?></span></td>
            <td class="muted small"><?= e($ecr['cat_libelle']) ?></td>
            <td class="num <?= $neg ? 'montant-neg' : 'montant-pos' ?>"><?= chf((float) $ecr['montant']) ?></td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
<span id="axe-ecritures-search-count" class="muted small"></span>
</div>
<script nonce="<?= e(csp_nonce()) ?>">
(function () {
    const search = document.getElementById('axe-ecritures-search');
    const count  = document.getElementById('axe-ecritures-search-count');
    const rows   = Array.from(document.querySelectorAll('.compta-lettrage tbody tr'));
    if (search) {
        const apply = () => {
            const q = lassoNorm(search.value.trim());
            let visibles = 0;
            rows.forEach(r => {
                const ok = q === '' || lassoNorm(r.textContent).includes(q);
                r.style.display = ok ? '' : 'none';
                if (ok) visibles++;
            });
            count.textContent = q === '' ? '' : visibles + ' / ' + rows.length + ' affichée(s)';
        };
        search.addEventListener('input', apply);
    }
})();
</script>
<?php endif; ?>
</div></div>
