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
    <div class="page-head-actions">
        <a href="?p=compta_axes" class="btn ghost btn-sm"><?= icon('settings') ?> Gérer les axes</a>
        <?php if ($ventilation): ?>
        <a class="btn ghost" href="?p=compta_analyse_print&annee=<?= (int) $annee ?>" target="_blank" rel="noopener"><?= icon('eye') ?> Aperçu</a>
        <?php endif; ?>
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
    <div class="section-head"></div>
    <p class="muted small" style="padding:4px 0 12px">
        Aucune écriture lettrée et ventilée pour <?= (int) $annee ?>.
        <a href="?p=compta_ecritures&annee=<?= (int) $annee ?>">Affecter un axe aux écritures.</a>
    </p>
</div>
<?php else: ?>
<div class="card" style="margin-top:8px">
    <div class="section-head"></div>
    <div class="table-scroll">
    <table class="list compta-cr">
        <thead>
            <tr>
                <th>Axe</th>
                <th class="num">Recettes</th>
                <th class="num">Dépenses</th>
                <th class="num">Résultat</th>
                <th style="width:36px"></th>
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
            $detail   = $detailParAxe[(int) $v['id']] ?? [];
            $nbCats   = count($detail);
        ?>
            <tr class="cr-groupe cr-clic" data-axe="<?= (int) $v['id'] ?>" tabindex="0" role="button" aria-expanded="false">
                <td>
                    <span class="cr-toggle" aria-hidden="true"><?= icon('chevron') ?></span>
                    <?= e($v['libelle']) ?>
                    <?php if ($v['code']): ?><span class="muted small"> · <?= e($v['code']) ?></span><?php endif; ?>
                    <?php if (!$v['actif']): ?><span class="badge muted-badge" style="margin-left:6px">inactif</span><?php endif; ?>
                    <?php if ($nbCats): ?><span class="cr-count"><?= $nbCats ?></span><?php endif; ?>
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
                <td>
                    <a class="btn-icon ghost" href="?p=compta_analyse_axe_print&axe=<?= (int) $v['id'] ?>&annee=<?= (int) $annee ?>" target="_blank" rel="noopener" title="Aperçu" onclick="event.stopPropagation()"><?= icon('eye') ?></a>
                </td>
            </tr>
            <tr class="cr-detail" data-axe="<?= (int) $v['id'] ?>" hidden>
                <td colspan="5">
                <?php if ($detail):
                    $curSens = null;
                    foreach ($detail as $pid => $cat):
                        if ($cat['sens'] !== $curSens):
                            $curSens = $cat['sens'];
                ?>
                    <div class="muted small" style="padding: 8px 0 2px; font-weight:600; text-transform:uppercase; font-size:11px; letter-spacing:.05em">
                        <?= $curSens === 'produit' ? 'Recettes' : 'Dépenses' ?>
                    </div>
                <?php  endif; ?>
                    <div class="cr-axe-cat">
                        <div class="cr-axe-cat-head">
                            <span><?= e($cat['libelle']) ?></span>
                            <span class="num <?= $cat['montant'] > 0 ? 'montant-pos' : ($cat['montant'] < 0 ? 'montant-neg' : '') ?>"><?= chf($cat['montant']) ?></span>
                        </div>
                        <?php foreach ($cat['lignes'] as $l):
                            $neg = (float) $l['montant'] < 0;
                        ?>
                        <div class="cr-det-row">
                            <span class="dt"><?= e(date('d.m.Y', strtotime((string) $l['date_op']))) ?></span>
                            <span class="cpt"><?= e($l['compte']) ?></span>
                            <span class="tx" title="<?= e($l['texte']) ?>"><?= e($l['texte']) ?></span>
                            <span class="mt <?= $neg ? 'montant-neg' : 'montant-pos' ?>"><?= chf((float) $l['montant']) ?></span>
                        </div>
                        <?php endforeach; ?>
                    </div>
                <?php endforeach; ?>
                <?php else: ?>
                    <p class="muted small" style="padding:8px 0">Aucune écriture ventilée sur cet axe.</p>
                <?php endif; ?>

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
                <td></td>
            </tr>
        </tfoot>
    </table>
    </div>
    <p class="muted small" style="padding:8px 12px 4px">
        Les écritures non ventilées ne sont pas incluses.
        <a href="?p=compta_ecritures&annee=<?= (int) $annee ?>&axe=sans_axe">Voir les écritures lettrées sans axe.</a>
    </p>
</div>

<script>
(function () {
    function toggle(row) {
        const axe = row.getAttribute('data-axe');
        const det = document.querySelector('.cr-detail[data-axe="' + axe + '"]');
        if (!det) return;
        const open = det.hasAttribute('hidden');
        det.toggleAttribute('hidden', !open);
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
<?php endif; ?>
