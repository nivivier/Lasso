<?php
/** @var int $annee */ /** @var array $annees */ /** @var array $axes */ /** @var array $ventilation */
?>
<div class="page-head">
    <div class="page-head-title">
        <h1>Comptabilité analytique</h1>
        <form method="get">
            <input type="hidden" name="p" value="compta_analyse">
            <select name="annee" class="inline-year-select" onchange="this.form.submit()">
                <?php foreach ($annees as $a): ?>
                    <option value="<?= (int) $a ?>" <?= (int) $a === $annee ? 'selected' : '' ?>><?= (int) $a ?></option>
                <?php endforeach; ?>
            </select>
        </form>
    </div>
    <div class="page-head-actions">
        <a href="?p=compta_axes" class="btn ghost btn-sm"><?= icon('settings') ?> Gérer les axes</a>
    </div>
</div>

<?php if (!$axes): ?>
<div class="card" style="margin-top:8px">
    <div class="section-head">
        <h2>Comptabilité analytique</h2>
        <a href="?p=compta_axes" class="btn btn-sm" style="margin-left:auto"><?= icon('plus') ?> Créer des axes</a>
    </div>
    <p class="muted small" style="padding:4px 0 12px">
        Aucun axe analytique défini. Créez des axes (ex. Label, Tour, Stages, Local)
        pour ventiler les écritures par activité et obtenir le résultat par axe.
    </p>
</div>
<?php elseif (!$ventilation): ?>
<div class="card" style="margin-top:8px">
    <div class="section-head"><h2>Comptabilité analytique <?= (int) $annee ?></h2></div>
    <p class="muted small" style="padding:4px 0 12px">
        Aucune écriture lettrée et ventilée pour <?= (int) $annee ?>.
        <a href="?p=compta_ecritures&annee=<?= (int) $annee ?>">Affecter un axe aux écritures.</a>
    </p>
</div>
<?php else: ?>
<div class="card" style="margin-top:8px">
    <div class="section-head">
        <h2>Comptabilité analytique <?= (int) $annee ?></h2>
    </div>
    <div class="table-scroll">
    <table class="list compta-cr">
        <thead>
            <tr>
                <th>Axe</th>
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
            <tr>
                <td>
                    <?= e($v['libelle']) ?>
                    <?php if ($v['code']): ?><span class="muted small"> · <?= e($v['code']) ?></span><?php endif; ?>
                    <?php if (!$v['actif']): ?><span class="badge muted-badge" style="margin-left:6px">inactif</span><?php endif; ?>
                </td>
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
                <td class="num"><?= chf($totProd) ?></td>
                <td class="num"><?= chf($totChg) ?></td>
                <td class="num"><?= chf($totProd + $totChg) ?></td>
            </tr>
        </tfoot>
    </table>
    </div>
    <p class="muted small" style="padding:8px 12px 4px">
        Les écritures non ventilées ne sont pas incluses.
        <a href="?p=compta_ecritures&annee=<?= (int) $annee ?>&axe=sans_axe">Voir les écritures lettrées sans axe.</a>
    </p>
</div>
<?php endif; ?>
