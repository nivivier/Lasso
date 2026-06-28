<?php
/** @var int $annee */ /** @var array $annees */ /** @var array $axes */
/** @var array $ventilation */ /** @var array $detailParAxe */
?>
<div class="page-head">
    <div class="page-head-title">
        <h1>Comptabilité analytique</h1>
        <form method="get">
            <input type="hidden" name="p" value="compta_analyse">
            <select name="annee" class="inline-year-select" onchange="this.form.submit()">
                <option value="0" <?= $annee === 0 ? 'selected' : '' ?>>Toutes</option>
                <?php foreach ($annees as $a): ?>
                    <option value="<?= (int) $a ?>" <?= (int) $a === $annee ? 'selected' : '' ?>><?= (int) $a ?></option>
                <?php endforeach; ?>
            </select>
        </form>
    </div>
    <div class="head-actions">
        <a href="?p=compta_axes" class="btn ghost btn-sm"><?= icon('settings') ?> Gérer les axes</a>
        <?php if ($axes): ?>
        <a href="?p=compta_suggestion_ventilation&annee=<?= (int) $annee ?>" class="btn ghost btn-sm"><?= icon('wand') ?> Suggérer ventilation charges</a>
        <?php endif; ?>
        <?php if ($ventilation): ?>
        <a class="btn ghost" href="?p=compta_analyse_print&annee=<?= (int) $annee ?>" data-preview target="_blank" rel="noopener"><?= icon('eye') ?> Aperçu</a>
        <?php endif; ?>
    </div>
</div>

<?php if (!$axes): ?>
<div class="card">
    <div class="section-head">
        <h2>Comptabilité analytique</h2>
        <a href="?p=compta_axes" class="btn btn-sm ml-auto"><?= icon('plus') ?> Créer des axes</a>
    </div>
    <p class="muted small card-note">
        Aucun axe analytique défini. Créez des axes (ex. Label, Tour, Stages, Local)
        pour ventiler les écritures par activité et obtenir le résultat par axe.
    </p>
</div>
<?php elseif (!$ventilation): ?>
<div class="card">
    <div class="section-head"></div>
    <p class="muted small card-note">
        Aucune écriture lettrée et ventilée<?= $annee ? ' pour ' . (int) $annee : '' ?>.
        <a href="?p=compta_ecritures&annee=<?= (int) $annee ?>">Affecter un axe aux écritures.</a>
    </p>
</div>
<?php else: ?>

    <div class="section-head"></div>
    <div class="table-scroll">
    <table class="list compta-cr cr-flat">
        <thead>
            <tr>
                <th>Axe</th>
                <th>Code</th>
                <th class="num">Recettes</th>
                <th class="num">Dépenses</th>
                <th class="num">Résultat</th>
            </tr>
        </thead>
        <tbody>
        <?php
        $totProd = 0.0;
        $totChg  = 0.0;
        foreach ($ventilation as $v):
            $res      = $v['resultat'];
            $totProd += $v['produits'];
            $totChg  += $v['charges'];
        ?>
            <tr class="cr-groupe cr-clic row-link" data-href="?p=compta_analyse_axe&axe=<?= (int) $v['id'] ?>&annee=<?= (int) $annee ?>">
                <td>
                    <?= e($v['libelle']) ?>
                    <?php if (!$v['actif']): ?><span class="badge muted-badge">inactif</span><?php endif; ?>
                </td>
                <td class="muted small"><?= $v['code'] ? e($v['code']) : '' ?></td>
                <td class="num <?= $v['produits'] > 0 ? 'montant-pos' : '' ?>">
                    <?= $v['produits'] != 0 ? chf($v['produits']) : '<span class="muted">—</span>' ?>
                </td>
                <td class="num <?= $v['charges'] < 0 ? 'montant-neg' : '' ?>">
                    <?= $v['charges'] != 0 ? chf($v['charges']) : '<span class="muted">—</span>' ?>
                </td>
                <td class="num strong <?= $res > 0 ? 'montant-pos' : ($res < 0 ? 'montant-neg' : '') ?>">
                    <?= $res != 0 ? chf($res) : '<span class="muted">—</span>' ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
        <tfoot>
            <tr class="cr-total">
                <td>Total ventilé</td>
                <td></td>
                <td class="num"><?= chf($totProd) ?></td>
                <td class="num"><?= chf($totChg) ?></td>
                <td class="num"><?= chf($totProd + $totChg) ?></td>
            </tr>
        </tfoot>
    </table>
    </div>
    <p class="muted small compta-cr-foot">
        Les écritures non ventilées ne sont pas incluses.
        <a href="?p=compta_ecritures&annee=<?= (int) $annee ?>&axe=sans_axe">Voir les écritures lettrées sans axe.</a>
    </p>
<?php endif; ?>
