<?php
/** @var int $annee */ /** @var array $annees */ /** @var array $cols */ /** @var int $nbPrec */
/** @var array $resultat */ /** @var array $sommesParAnnee */ /** @var array $totauxParAnnee */
/** @var array $continuite */ /** @var array $plan */ /** @var array $lignesParCat */
/** @var array $patrimoine */ /** @var array $ventilation */

$byParent = plan_enfants($plan);
$nbCols   = count($cols);

// Rendu d'un sens (produit / charge) en arbre, une colonne de montant par année.
$blocSens = function (string $sens, string $titre) use ($byParent, $sommesParAnnee, $lignesParCat, $cols, $nbCols, $annee): string {
    $pad = fn(int $p) => 'style="padding-left:' . (16 + $p * 22) . 'px"';
    $cellules = function (callable $val) use ($cols, $annee): string {
        $h = '';
        foreach ($cols as $a) {
            $cls = (int) $a !== $annee ? ' col-prec' : '';
            $h .= '<td class="num' . $cls . '">' . chf($val((int) $a)) . '</td>';
        }
        return $h;
    };
    $rendre = function (array $row, int $prof) use (&$rendre, $byParent, $sommesParAnnee, $lignesParCat, $pad, $cellules, $cols, $nbCols, $annee): string {
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
        // Feuille : ligne cliquable (le détail concerne l'année sélectionnée).
        $lignes = $lignesParCat[$id] ?? [];
        $h = '<tr class="cr-compte cr-clic" data-cat="' . $id . '" tabindex="0" role="button" aria-expanded="false">'
           . '<td ' . $pad($prof) . '><span class="cr-toggle" aria-hidden="true">' . icon('chevron') . '</span>'
           . e($row['libelle']) . ' <span class="cr-count">' . count($lignes) . '</span></td>'
           . $cellules(fn(int $a) => (float) ($sommesParAnnee[$a][$id] ?? 0)) . '</tr>';
        $det = '';
        foreach ($lignes as $l) {
            $neg = (float) $l['montant'] < 0;
            $det .= '<div class="cr-det-row">'
                 . '<span class="dt">' . e(date('d.m.Y', strtotime((string) $l['date_op']))) . '</span>'
                 . '<span class="cpt">' . e($l['compte']) . '</span>'
                 . '<span class="tx" title="' . e($l['texte']) . '">' . e($l['texte']) . '</span>'
                 . '<span class="mt ' . ($neg ? 'montant-neg' : 'montant-pos') . '">' . chf((float) $l['montant']) . '</span>'
                 . '</div>';
        }
        $libDet = $nbCols > 1 ? '<div class="muted small mb-0">Détail ' . (int) $annee . ' :</div>' : '';
        $h .= '<tr class="cr-detail" data-cat="' . $id . '" hidden><td colspan="' . ($nbCols + 1) . '">' . $libDet . $det . '</td></tr>';
        return $h;
    };
    $racines = array_filter($byParent[0] ?? [], fn($r) => $r['sens'] === $sens);
    $h = '<tr class="cr-section"><th colspan="' . ($nbCols + 1) . '">' . e($titre) . '</th></tr>';
    if (!$racines) {
        $h .= '<tr><td colspan="' . ($nbCols + 1) . '" class="muted small">Aucune catégorie.</td></tr>';
    }
    foreach ($racines as $r) {
        $h .= $rendre($r, 0);
    }
    return $h;
};
?>
<div class="page-head">
    <div class="page-head-title">
        <h1>Comptes annuels</h1>
        <form method="get">
            <input type="hidden" name="p" value="compta_bilan">
            <select name="annee" class="inline-year-select" onchange="this.form.submit()">
                <?php foreach ($annees as $a): ?>
                    <option value="<?= (int) $a ?>" <?= $annee === (int) $a ? 'selected' : '' ?>><?= (int) $a ?></option>
                <?php endforeach; ?>
            </select>
        </form>
    </div>
    <div class="head-actions">
        <label class="inline" style="font-size:13px;font-weight:500;color:var(--muted);gap:6px">
            Années précédentes
            <select class="inline-year-select" onchange="location.href='?p=compta_bilan&annee=<?= (int) $annee ?>&prec='+this.value">
                <?php foreach ([0,1,2,3] as $n): ?>
                    <option value="<?= $n ?>" <?= $nbPrec === $n ? 'selected' : '' ?>><?= $n ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <a class="btn ghost" href="?p=compta_bilan_print&annee=<?= (int) $annee ?>&prec=<?= $nbPrec ?>" target="_blank" rel="noopener"><?= icon('printer') ?> Imprimer / PDF</a>
    </div>


</div>


<?php if ($continuite): ?>
<div class="err mb-16">
    <strong>Report à nouveau — contrôle de continuité :</strong>
    <ul class="mb-0">
        <?php foreach ($continuite as $a): ?>
        <li><?= e($a['compte']) ?> : solde d'ouverture <?= (int) $a['annee'] ?> (<?= chf($a['ouverture']) ?>) ≠ clôture <?= (int) $a['annee'] - 1 ?> (<?= chf($a['cloture_prec']) ?>) — un export est peut-être manquant entre les deux exercices.</li>
        <?php endforeach; ?>
    </ul>
</div>
<?php endif; ?>

<div class="card bilan-card">
    <div class="section-head">
        <h2>État du patrimoine</h2>
        <a href="?p=compta_comptes" class="btn ghost btn-sm" style="margin-left:auto"><?= icon('pencil') ?> Comptes bancaires</a>
    </div>
    <div class="table-scroll">
    <table class="list">
        <thead>
            <tr><th>Poste</th><?php foreach ($cols as $a): ?><th class="num<?= (int)$a !== $annee ? ' col-prec' : '' ?>">au 31.12.<?= (int) $a ?></th><?php endforeach; ?></tr>
        </thead>
        <tbody>
            <?php foreach ($patrimoine as $p): ?>
            <tr>
                <td><?= e($p['libelle']) ?></td>
                <?php foreach ($cols as $a): $v = $p['valeurs'][$a] ?? null; ?>
                    <td class="num<?= (int)$a !== $annee ? ' col-prec' : '' ?>"><?= $v === null ? '<span class="muted">—</span>' : chf((float) $v) ?></td>
                <?php endforeach; ?>
            </tr>
            <?php endforeach; ?>
            <tr class="cr-total">
                <td>Total du patrimoine</td>
                <?php foreach ($cols as $a): ?><td class="num<?= (int)$a !== $annee ? ' col-prec' : '' ?>"><?= chf(compta_total_patrimoine((int) $a, $patrimoine)) ?></td><?php endforeach; ?>
            </tr>
        </tbody>
    </table>
    </div>
</div>


<div class="card bilan-card" style="margin-top:28px">
    <div class="section-head">
        <h2>Compte de résultat<?= $nbCols > 1 ? '' : ' ' . (int) $annee ?></h2>
        <a href="?p=compta_plan" class="btn ghost btn-sm" style="margin-left:auto"><?= icon('pencil') ?> Plan comptable</a>
    </div>
    <?php if ($resultat['non_lettrees']['nb'] > 0): ?>
<p class="err"><?= (int) $resultat['non_lettrees']['nb'] ?> écriture(s) de <?= (int) $annee ?> non lettrée(s)
   (<?= chf($resultat['non_lettrees']['montant']) ?> CHF) ne sont pas prises en compte.
   <a href="?p=compta_ecritures&annee=<?= (int) $annee ?>&categorie=a_lettrer">Les lettrer</a>.</p>
<?php endif; ?>
    <div class="table-scroll">
    <table class="list compta-cr">
        <?php if ($nbCols > 1): ?>
        <thead><tr><th>Catégorie</th><?php foreach ($cols as $a): ?><th class="num<?= (int)$a !== $annee ? ' col-prec' : '' ?>"><?= (int) $a ?></th><?php endforeach; ?></tr></thead>
        <?php endif; ?>
        <tbody>
            <?= $blocSens('produit', 'Recettes') ?>
            <?= compta_ligne_total('Total des recettes', 'produits', 'cr-total', $cols, $totauxParAnnee) ?>
            <?= $blocSens('charge', 'Dépenses') ?>
            <?= compta_ligne_total('Total des dépenses', 'charges', 'cr-total', $cols, $totauxParAnnee) ?>
            <?= compta_ligne_total('Résultat de l\'année', 'resultat', 'cr-resultat', $cols, $totauxParAnnee) ?>
        </tbody>
    </table>
    </div>
</div>

<?php if ($ventilation): ?>
<div class="card bilan-card" style="margin-top:28px">
    <div class="section-head">
        <h2>Ventilation analytique <?= (int) $annee ?></h2>
        <a href="?p=compta_axes" class="btn ghost btn-sm" style="margin-left:auto"><?= icon('pencil') ?> Gérer les axes</a>
    </div>
    <div class="table-scroll">
    <table class="list">
        <thead>
            <tr>
                <th>Axe</th>
                <th class="num">Recettes</th>
                <th class="num">Dépenses</th>
                <th class="num">Résultat</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($ventilation as $v): $res = $v['resultat']; ?>
            <tr>
                <td><?= e($v['libelle']) ?><?php if ($v['code']): ?> <span class="muted small"><?= e($v['code']) ?></span><?php endif; ?></td>
                <td class="num <?= $v['produits'] > 0 ? 'montant-pos' : '' ?>"><?= $v['produits'] != 0 ? chf($v['produits']) : '<span class="muted">—</span>' ?></td>
                <td class="num <?= $v['charges'] < 0 ? 'montant-neg' : '' ?>"><?= $v['charges'] != 0 ? chf($v['charges']) : '<span class="muted">—</span>' ?></td>
                <td class="num strong <?= $res > 0 ? 'montant-pos' : ($res < 0 ? 'montant-neg' : '') ?>"><?= $res != 0 ? chf($res) : '<span class="muted">—</span>' ?></td>
            </tr>
        <?php endforeach; ?>
        <?php
        $totProd = array_sum(array_column($ventilation, 'produits'));
        $totChg  = array_sum(array_column($ventilation, 'charges'));
        $totRes  = $totProd + $totChg;
        ?>
        <tr class="cr-total">
            <td>Total ventilé</td>
            <td class="num"><?= chf($totProd) ?></td>
            <td class="num"><?= chf($totChg) ?></td>
            <td class="num"><?= chf($totRes) ?></td>
        </tr>
        </tbody>
    </table>
    </div>
    <p class="muted small" style="padding:8px 12px 0">
        Les écritures non ventilées ne sont pas incluses.
        <a href="?p=compta_ecritures&annee=<?= (int) $annee ?>&axe=sans_axe">Voir les écritures lettrées sans axe.</a>
    </p>
</div>
<?php else: ?>
<div class="card bilan-card" style="margin-top:28px">
    <div class="section-head">
        <h2>Ventilation analytique</h2>
        <a href="?p=compta_axes" class="btn btn-sm" style="margin-left:auto"><?= icon('plus') ?> Créer des axes</a>
    </div>
    <p class="muted small" style="padding:4px 0 8px">Aucun axe analytique défini. Créez des axes (ex. Label, Tour, Stages, Local) pour ventiler les écritures par activité.</p>
</div>
<?php endif; ?>

<script>
(function () {
    // Clic (ou Entrée/Espace) sur une catégorie → déplie ses écritures.
    function toggle(row) {
        const cat = row.getAttribute('data-cat');
        const det = document.querySelector('.cr-detail[data-cat="' + cat + '"]');
        if (!det) return;
        const open = det.hasAttribute('hidden');
        if (open) { det.removeAttribute('hidden'); } else { det.setAttribute('hidden', ''); }
        row.classList.toggle('open', open);
        row.setAttribute('aria-expanded', open ? 'true' : 'false');
    }
    document.querySelectorAll('.cr-clic').forEach(row => {
        row.addEventListener('click', () => toggle(row));
        row.addEventListener('keydown', e => {
            if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); toggle(row); }
        });
    });

})();
</script>
