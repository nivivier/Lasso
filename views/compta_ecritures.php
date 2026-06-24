<?php
/** @var array $comptes */ /** @var int $compteId */ /** @var int $annee */ /** @var array $annees */
/** @var string $statut */ /** @var array $ecritures */ /** @var array $feuilles */
/** @var int $nbALettrer */ /** @var ?string $rules */

// <option> des catégories assignables (feuilles), libellées par leur chemin.
$catOptions = function ($selected, string $vide = '— à lettrer —') use ($feuilles): string {
    $sel = $selected === null ? '' : (string) $selected;
    $html = '<option value=""' . ($sel === '' ? ' selected' : '') . '>' . e($vide) . '</option>';
    foreach ($feuilles as $f) {
        $s = $sel === (string) $f['id'] ? ' selected' : '';
        $html .= '<option value="' . (int) $f['id'] . '"' . $s . '>' . e($f['chemin']) . '</option>';
    }
    return $html;
};
// Query string des filtres courants (pour conserver le filtre après POST).
$qs = '&compte=' . $compteId . '&annee=' . $annee . '&statut=' . urlencode($statut);
?>
<div class="page-head">
    <div class="page-head-title">
        <h1>Écritures</h1>
        <form method="get" id="annee-form">
            <input type="hidden" name="p" value="compta_ecritures">
            <input type="hidden" name="compte" value="<?= $compteId ?>">
            <input type="hidden" name="statut" value="<?= e($statut) ?>">
            <select name="annee" class="inline-year-select" onchange="this.form.submit()">
                <option value="0">Toutes</option>
                <?php foreach ($annees as $a): ?>
                    <option value="<?= (int) $a ?>" <?= $annee === (int) $a ? 'selected' : '' ?>><?= (int) $a ?></option>
                <?php endforeach; ?>
            </select>
        </form>
    </div>
    <div class="head-actions">
        <a href="?p=compta_regles" class="btn ghost"><?= icon('settings') ?> Lettrage automatique</a>
        <a href="?p=compta_import" class="btn"><?= icon('upload') ?> Importer</a>
    </div>
</div>
<?php if ($rules !== null): ?><p class="ok flash"><?= (int) $rules ?> écriture(s) lettrée(s) par les règles.</p><?php endif; ?>

<form method="get" class="filters card-soft">
    <input type="hidden" name="p" value="compta_ecritures">
    <input type="hidden" name="annee" value="<?= $annee ?>">
    <label>Compte
        <select name="compte" onchange="this.form.submit()">
            <option value="0" <?= $compteId === 0 ? 'selected' : '' ?>>Tous les comptes</option>
            <?php foreach ($comptes as $c): ?>
                <option value="<?= (int) $c['id'] ?>" <?= $compteId === (int) $c['id'] ? 'selected' : '' ?>><?= e($c['libelle']) ?></option>
            <?php endforeach; ?>
        </select>
    </label>
    <label>Statut
        <select name="statut" onchange="this.form.submit()">
            <?php foreach (['tous' => 'Toutes', 'a_lettrer' => 'À lettrer', 'lettre' => 'Lettrées'] as $k => $lib): ?>
                <option value="<?= $k ?>" <?= $statut === $k ? 'selected' : '' ?>><?= e($lib) ?></option>
            <?php endforeach; ?>
        </select>
    </label>
    <label class="search-label"><span>Rechercher <span id="search-count" class="muted small"></span></span>
        <input type="search" id="compta-search" placeholder="Texte, montant, catégorie…" autocomplete="off" aria-label="Rechercher">
    </label>
</form>

<?php if (!$comptes): ?>
    <p class="muted">Aucun compte bancaire. Commencez par en <a href="?p=compta_comptes">créer un</a> puis <a href="?p=compta_import">importer</a> un export.</p>
<?php elseif (!$ecritures): ?>
    <p class="muted">Aucune écriture pour ce filtre.</p>
<?php else: ?>



<div class="card">

<div class="bulk-bar" id="bulk-bar" hidden>
    <span class="bulk-titre muted small">Lettrage groupé</span>
    <form method="post" id="bulkform" action="?p=compta_ecritures<?= $qs ?>">
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="section" value="lettrer">
        <select name="plan_compte_id"><?= $catOptions(null, '— Retirer le lettrage —') ?></select>
        <button type="submit">Appliquer à la sélection</button>
    </form>
</div>

<div class="table-scroll">
<table class="list compta-lettrage">
    <thead>
        <tr>
            <th class="col-check"><input type="checkbox" id="check-all" aria-label="Tout cocher"></th>
            <th>Date</th>
            <?php if ($compteId === 0): ?><th>Compte</th><?php endif; ?>
            <th>Texte</th>
            <th class="num">Montant</th>
            <th>Catégorie</th>
            <th></th>
        </tr>
    </thead>
    <tbody>
    <?php
    $prevMois = null;
    $moisFr = ['01'=>'Janvier','02'=>'Février','03'=>'Mars','04'=>'Avril','05'=>'Mai','06'=>'Juin','07'=>'Juillet','08'=>'Août','09'=>'Septembre','10'=>'Octobre','11'=>'Novembre','12'=>'Décembre'];
    $nbCols = 6 + ($compteId === 0 ? 1 : 0);
    foreach ($ecritures as $ecr): $neg = (float) $ecr['montant'] < 0;
        $moisCle = substr((string) $ecr['date_op'], 0, 7);
        if ($moisCle !== $prevMois):
            $prevMois = $moisCle;
    ?>
        <tr class="ecr-mois-sep"><td colspan="<?= $nbCols ?>">
            <?= e($moisFr[substr($moisCle, 5, 2)] ?? substr($moisCle, 5, 2)) . ' ' . substr($moisCle, 0, 4) ?>
        </td></tr>
    <?php endif; ?>
        <tr class="<?= $ecr['plan_compte_id'] === null ? 'non-lettre' : '' ?>">
            <td class="col-check"><input type="checkbox" name="ids[]" value="<?= (int) $ecr['id'] ?>" form="bulkform" class="row-check"></td>
            <td class="nowrap"><?= e(date('d.m.Y', strtotime((string) $ecr['date_op']))) ?></td>
            <?php if ($compteId === 0): ?><td class="compte-cell small muted"><?= e($ecr['compte_libelle']) ?></td><?php endif; ?>
            <td class="texte-cell" title="<?= e($ecr['texte']) ?>" data-summary="<?= e(resumer_texte_postfinance($ecr['texte'])) ?>"><?= e(resumer_texte_postfinance($ecr['texte'])) ?></td>
            <td class="num <?= $neg ? 'montant-neg' : 'montant-pos' ?>"><?= chf((float) $ecr['montant']) ?></td>
            <td class="cat-cell">
                <form method="post" action="?p=compta_ecritures<?= $qs ?>">
                    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="section" value="lettrer">
                    <input type="hidden" name="ids[]" value="<?= (int) $ecr['id'] ?>">
                    <select name="plan_compte_id" onchange="this.form.submit()"><?= $catOptions($ecr['plan_compte_id']) ?></select>
                </form>
            </td>
            <td class="actions">
                <a class="btn ghost btn-sm" title="Créer une règle depuis cette écriture"
                   href="?p=compta_regles&motif=<?= urlencode($ecr['texte']) ?>&compte=<?= (int) $ecr['compte_bancaire_id'] ?>"><?= icon('tag') ?></a>
            </td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
</div>
</div>

<script>
(function () {
    const bulkBar = document.getElementById('bulk-bar');
    function updateBulkBar() {
        const n = document.querySelectorAll('.row-check:checked').length;
        if (bulkBar) bulkBar.hidden = n === 0;
    }

    const all = document.getElementById('check-all');
    if (all) all.addEventListener('change', () => {
        document.querySelectorAll('.row-check:not(.is-hidden)').forEach(c => { c.checked = all.checked; });
        updateBulkBar();
    });
    document.querySelectorAll('.row-check').forEach(c => c.addEventListener('change', updateBulkBar));

    // Clic sur le texte → bascule résumé ↔ texte brut complet.
    document.querySelectorAll('.compta-lettrage .texte-cell').forEach(td => {
        td.addEventListener('click', () => {
            const expanded = td.classList.toggle('expanded');
            td.textContent = expanded ? td.title : td.dataset.summary;
        });
    });

    // Recherche instantanée (insensible à la casse et aux accents).
    const search = document.getElementById('compta-search');
    const count  = document.getElementById('search-count');
    const rows   = Array.from(document.querySelectorAll('.compta-lettrage tbody tr'));
    const norm = s => s.toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '');
    // Texte recherchable d'une ligne : date + intitulé + montant + catégorie
    // sélectionnée (et NON toute la liste déroulante des catégories).
    const texteLigne = r => {
        const date = r.querySelector('td.nowrap')?.textContent || '';
        const cpt  = r.querySelector('.compte-cell')?.textContent || '';
        const txt  = r.querySelector('.texte-cell')?.textContent || '';
        const mt   = r.querySelector('td.num')?.textContent || '';
        const sel  = r.querySelector('.cat-cell select');
        const cat  = sel ? sel.options[sel.selectedIndex].text : '';
        return norm(date + ' ' + cpt + ' ' + txt + ' ' + mt + ' ' + cat);
    };
    if (search) {
        const apply = () => {
            const q = norm(search.value.trim());
            let visibles = 0;
            rows.forEach(r => {
                const ok = q === '' || texteLigne(r).includes(q);
                r.style.display = ok ? '' : 'none';
                const cb = r.querySelector('.row-check');
                if (cb) cb.classList.toggle('is-hidden', !ok);
                if (ok) visibles++;
            });
            count.textContent = q === '' ? '' : visibles + ' / ' + rows.length + ' affichée(s)';
        };
        search.addEventListener('input', apply);
    }
})();
</script>
<?php endif; ?>
