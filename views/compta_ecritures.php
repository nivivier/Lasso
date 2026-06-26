<?php
/** @var array $comptes */ /** @var int $compteId */ /** @var int $annee */ /** @var array $annees */
/** @var string $categorieFilter */ /** @var string $axeFilter */ /** @var array $ecritures */
/** @var array $ventilationsParEcr */ /** @var array $feuilles */ /** @var array $axes */
/** @var ?string $rules */ /** @var ?array $editEcr */ /** @var bool $openNew */

// Map id → chemin et id → {prefix, leaf} pour les inputs individuels.
$cheminById    = [];
$catPrefixById = [];
$catLeafById   = [];
foreach ($feuilles as $f) {
    $ch = $f['chemin'];
    $cheminById[(int) $f['id']] = $ch;
    $sep = mb_strrpos($ch, ' › ');
    if ($sep !== false) {
        $catPrefixById[(int) $f['id']] = mb_substr($ch, 0, $sep);
        $catLeafById[(int) $f['id']]   = mb_substr($ch, $sep + 3);
    } else {
        $catPrefixById[(int) $f['id']] = '';
        $catLeafById[(int) $f['id']]   = $ch;
    }
}

// Pour les dropdowns : produits d'abord puis charges, ordre du plan respecté dans chaque sens.
$feuillesSorted = array_values(array_merge(
    array_filter($feuilles, fn($f) => $f['sens'] === 'produit'),
    array_filter($feuilles, fn($f) => $f['sens'] !== 'produit')
));

// Formulaire écriture manuelle (création ou modification).
$showForm = $openNew || $editEcr !== null;
$isEdit   = $editEcr !== null;
$qs = '&compte=' . $compteId . '&annee=' . $annee . '&categorie=' . urlencode($categorieFilter) . '&axe=' . urlencode($axeFilter);
$axeLabel = fn(array $ax): string => ($ax['code'] !== '' && $ax['code'] !== null) ? $ax['code'] : $ax['libelle'];

// Fonction : composant cat-search réutilisable (partagé bulk + form manuel).
$catSearchField = function (string $name, ?int $selected, string $placeholder, bool $ignore = false) use ($feuillesSorted, $cheminById, $catPrefixById): string {
    $initChemin = $ignore ? 'Ne pas lettrer' : ($selected !== null ? ($cheminById[$selected] ?? '') : '');
    $hiddenVal  = $ignore ? 'ignore' : ($selected ?? '');
    $items = '';
    $sensCourant = null; $grpCourant = null;
    foreach ($feuillesSorted as $f) {
        $fid = (int) $f['id'];
        $grp = $catPrefixById[$fid] ?? '';
        if ($f['sens'] !== $sensCourant) { $sensCourant = $f['sens']; $grpCourant = null; $items .= '<li class="cat-search-sens">' . ($sensCourant === 'produit' ? 'Recettes' : 'Dépenses') . '</li>'; }
        if ($grp !== $grpCourant) { $grpCourant = $grp; if ($grp !== '') $items .= '<li class="cat-search-group">' . e($grp) . '</li>'; }
        $items .= '<li data-val="' . $fid . '">' . e($f['chemin']) . '</li>';
    }
    return '<div class="cat-search form-cat-search">'
         . '<input type="text" class="cat-search-input" placeholder="' . e($placeholder) . '" autocomplete="off" value="' . e($initChemin) . '">'
         . '<input type="hidden" name="' . e($name) . '" class="cat-search-val" value="' . e($hiddenVal) . '">'
         . '<ul class="cat-search-list" hidden role="listbox"><li data-val="">— Sans catégorie —</li>'
         . '<li data-val="ignore">Ne pas lettrer</li>' . $items . '</ul>'
         . '</div>';
};
?>
<div class="page-head">
    <div class="page-head-title">
        <h1>Écritures</h1>
        <form method="get" id="annee-form">
            <input type="hidden" name="p" value="compta_ecritures">
            <input type="hidden" name="compte" value="<?= $compteId ?>">
            <input type="hidden" name="categorie" value="<?= e($categorieFilter) ?>">
            <input type="hidden" name="axe" value="<?= e($axeFilter) ?>">
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
        <button type="button" id="btn-new-ecr" class="btn ghost"><?= icon('plus') ?> Écriture manuelle</button>
        <a href="?p=compta_import" class="btn"><?= icon('upload') ?> Importer</a>
    </div>
</div>
<?php if ($rules !== null): ?><p class="ok flash"><?= (int) $rules ?> écriture(s) lettrée(s) par les règles.</p><?php endif; ?>

<!-- Formulaire écriture manuelle -->
<div class="card form ecr-manuel-form" id="ecr-manuel-form" <?= $showForm ? '' : 'hidden' ?>>
    <h3 class="form-subtitle"><?= $isEdit ? 'Modifier l\'écriture' : 'Nouvelle écriture manuelle' ?></h3>
    <form method="post" action="?p=compta_ecritures<?= $qs ?>">
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="section" value="<?= $isEdit ? 'update' : 'create' ?>">
        <?php if ($isEdit): ?><input type="hidden" name="id" value="<?= (int) $editEcr['id'] ?>"><?php endif; ?>
        <div class="grid3">
            <label>Compte
                <select name="compte_bancaire_id" required>
                    <?php foreach ($comptes as $c): ?>
                        <option value="<?= (int) $c['id'] ?>" <?= ($isEdit ? (int)$editEcr['compte_bancaire_id'] : $compteId) === (int)$c['id'] ? 'selected' : '' ?>><?= e($c['libelle']) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>Date
                <input type="date" name="date_op" required value="<?= e($isEdit ? $editEcr['date_op'] : date('Y-m-d')) ?>">
            </label>
            <label>Montant <span class="muted label-hint">(+ crédit, − débit)</span>
                <input type="number" name="montant" step="0.01" required value="<?= $isEdit ? (float)$editEcr['montant'] : '' ?>" placeholder="ex. -150.00">
            </label>
        </div>
        <label>Description
            <input type="text" name="texte" required value="<?= e($isEdit ? $editEcr['texte'] : '') ?>" placeholder="ex. Remboursement frais divers">
        </label>
        <div class="grid2-optional">
        <label>Catégorie <span class="muted label-hint">(optionnel)</span>
            <?= $catSearchField('plan_compte_id', $isEdit && $editEcr['plan_compte_id'] ? (int)$editEcr['plan_compte_id'] : null, 'Chercher une catégorie…', $isEdit && ($editEcr['origine_lettrage'] ?? '') === 'ignore') ?>
        </label>
        <?php if ($axes && !$isEdit): ?>
        <label>Axe analytique <span class="muted label-hint">(optionnel)</span>
            <select name="axe_analytique_id">
                <option value="">— Aucun —</option>
                <?php foreach ($axes as $ax): ?>
                    <option value="<?= (int) $ax['id'] ?>"><?= e($axeLabel($ax)) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <?php endif; ?>
        </div>
        <div class="form-actions">
            <button type="submit" class="btn"><?= icon('check') ?> <?= $isEdit ? 'Enregistrer' : 'Créer l\'écriture' ?></button>
            <a href="?p=compta_ecritures<?= $qs ?>" class="btn ghost">Annuler</a>
            <?php if ($isEdit): ?>
                <button type="submit" form="del-ecr-form" class="btn danger" onclick="return confirm('Supprimer cette écriture ?')"><?= icon('trash') ?> Supprimer</button>
            <?php endif; ?>
        </div>
    </form>
    <?php if ($isEdit): ?>
    <form id="del-ecr-form" method="post" action="?p=compta_ecritures<?= $qs ?>" hidden>
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="section" value="delete_manual">
        <input type="hidden" name="id" value="<?= (int) $editEcr['id'] ?>">
    </form>
    <?php endif; ?>
</div>

<form method="get" class="filters">
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
    <label>Catégorie
        <?php
        $filtreTexte = match($categorieFilter) {
            'a_lettrer' => '— À lettrer —',
            'ignore'    => '— Ne pas lettrer —',
            ''          => '',
            default     => $cheminById[(int) $categorieFilter] ?? '',
        };
        ?>
        <div class="cat-search filtre-cat-search">
            <input type="text" class="cat-search-input" placeholder="Toutes" autocomplete="off" value="<?= e($filtreTexte) ?>">
            <input type="hidden" name="categorie" class="cat-search-val" value="<?= e($categorieFilter) ?>">
            <ul class="cat-search-list" hidden role="listbox">
                <li data-val="">Toutes</li>
                <li data-val="a_lettrer">— À lettrer —</li>
                <li data-val="ignore">— Ne pas lettrer —</li>
                <?php $sensCourant = null; $grpCourant = null; foreach ($feuillesSorted as $f): $fid = (int) $f['id']; $grp = $catPrefixById[$fid] ?? ''; ?>
                <?php if ($f['sens'] !== $sensCourant): $sensCourant = $f['sens']; $grpCourant = null; ?>
                    <li class="cat-search-sens"><?= $sensCourant === 'produit' ? 'Recettes' : 'Dépenses' ?></li>
                <?php endif; ?>
                <?php if ($grp !== $grpCourant): $grpCourant = $grp; if ($grp !== ''): ?><li class="cat-search-group"><?= e($grp) ?></li><?php endif; endif; ?>
                    <li data-val="<?= $fid ?>"><?= e($f['chemin']) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    </label>
    <?php if ($axes): ?>
    <label>Axe
        <select name="axe" onchange="this.form.submit()">
            <option value="" <?= $axeFilter === '' ? 'selected' : '' ?>>Tous</option>
            <option value="sans_axe" <?= $axeFilter === 'sans_axe' ? 'selected' : '' ?>>— Sans axe —</option>
            <?php foreach ($axes as $ax): ?>
                <option value="<?= (int) $ax['id'] ?>" <?= $axeFilter === (string) $ax['id'] ? 'selected' : '' ?>><?= e($axeLabel($ax)) ?></option>
            <?php endforeach; ?>
        </select>
    </label>
    <?php endif; ?>
    <label class="search-label"><span>Rechercher <span id="search-count" class="muted small"></span></span>
        <input type="search" id="compta-search" placeholder="Texte, montant, catégorie…" autocomplete="off" aria-label="Rechercher">
    </label>
</form>

<?php if (!$comptes): ?>
    <p class="muted">Aucun compte bancaire. Commencez par en <a href="?p=compta_comptes">créer un</a> puis <a href="?p=compta_import">importer</a> un export.</p>
<?php elseif (!$ecritures): ?>
    <p class="muted">Aucune écriture pour ce filtre.</p>
<?php else: ?>





<div class="bulk-bar" id="bulk-bar" hidden>
    <div class="bulk-group">
        <span class="bulk-titre">Catégorie :</span>
        <form method="post" id="bulkform" action="?p=compta_ecritures<?= $qs ?>">
            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="section" value="lettrer">
            <div class="cat-search bulk-cat-search">
                <input type="text" class="cat-search-input" placeholder="Catégorie ou retirer le lettrage…" autocomplete="off">
                <input type="hidden" name="plan_compte_id" class="cat-search-val" value="">
                <ul class="cat-search-list" hidden role="listbox">
                    <li data-val="">— Retirer le lettrage —</li>
                    <li data-val="ignore">Ne pas lettrer</li>
                    <?php $sensCourant = null; $grpCourant = null; foreach ($feuillesSorted as $f): $fid = (int) $f['id']; $grp = $catPrefixById[$fid] ?? ''; ?>
                    <?php if ($f['sens'] !== $sensCourant): $sensCourant = $f['sens']; $grpCourant = null; ?><li class="cat-search-sens"><?= $sensCourant === 'produit' ? 'Recettes' : 'Dépenses' ?></li><?php endif; ?>
                    <?php if ($grp !== $grpCourant): $grpCourant = $grp; if ($grp !== ''): ?><li class="cat-search-group"><?= e($grp) ?></li><?php endif; endif; ?>
                        <li data-val="<?= $fid ?>"><?= e($f['chemin']) ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
            <button type="submit">Appliquer</button>
        </form>
    </div>
    <?php if ($axes): ?>
    <div class="bulk-group">
        <span class="bulk-titre">Axe :</span>
        <form method="post" id="axe-bulkform" action="?p=compta_ecritures<?= $qs ?>">
            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="section" value="axer">
            <select name="axe_analytique_id" class="inline-year-select">
                <option value="">— Retirer —</option>
                <?php foreach ($axes as $ax): ?>
                    <option value="<?= (int) $ax['id'] ?>"><?= e($axeLabel($ax)) ?></option>
                <?php endforeach; ?>
            </select>
            <button type="submit">Appliquer</button>
        </form>
    </div>
    <?php endif; ?>
</div>

<table class="list compta-lettrage">
    <thead>
        <tr>
            <th class="col-check"><input type="checkbox" id="check-all" aria-label="Tout cocher"></th>
            <th>Date</th>
            <?php if ($compteId === 0): ?><th>Compte</th><?php endif; ?>
            <th>Texte</th>
            <th class="num">Montant</th>
            <th>Catégorie</th>
            <?php if ($axes): ?><th>Axe</th><?php endif; ?>
            <th></th>
        </tr>
    </thead>
    <tbody>
    <?php
    $prevMois = null;
    $moisFr = ['01'=>'Janvier','02'=>'Février','03'=>'Mars','04'=>'Avril','05'=>'Mai','06'=>'Juin','07'=>'Juillet','08'=>'Août','09'=>'Septembre','10'=>'Octobre','11'=>'Novembre','12'=>'Décembre'];
    $nbCols = 6 + ($compteId === 0 ? 1 : 0) + ($axes ? 1 : 0);
    foreach ($ecritures as $ecr): $neg = (float) $ecr['montant'] < 0;
        $moisCle = substr((string) $ecr['date_op'], 0, 7);
        if ($moisCle !== $prevMois):
            $prevMois = $moisCle;
    ?>
        <tr class="ecr-mois-sep"><td colspan="<?= $nbCols ?>">
            <?= e($moisFr[substr($moisCle, 5, 2)] ?? substr($moisCle, 5, 2)) . ' ' . substr($moisCle, 0, 4) ?>
        </td></tr>
    <?php endif; ?>
        <?php
        $isManuel = $ecr['import_id'] === null;
        $estIgnore = $ecr['plan_compte_id'] === null && ($ecr['origine_lettrage'] ?? '') === 'ignore';
        $estNonLettre = $ecr['plan_compte_id'] === null && !$estIgnore;
        $rowCatVal   = $estIgnore ? 'ignore' : ($ecr['plan_compte_id'] ?? '');
        $pid = (int) ($ecr['plan_compte_id'] ?? 0);
        $rowCatPrefix = $estIgnore ? '' : ($catPrefixById[$pid] ?? '');
        $rowCatLeaf   = $estIgnore ? 'Ne pas lettrer' : ($catLeafById[$pid] ?? '');
        ?>
        <tr class="<?= $estNonLettre ? 'non-lettre' : '' ?><?= $estIgnore ? ' ecr-ignore' : '' ?><?= $isManuel ? ' ecr-manuelle' : '' ?>">
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
                    <input type="hidden" name="plan_compte_id" class="row-cat-val" value="<?= e($rowCatVal) ?>">
                    <?php if ($rowCatVal !== ''): ?>
                    <div class="row-field-disp">
                        <span class="row-field-txt"><?php if ($rowCatPrefix !== ''): ?><span class="row-field-prefix"><?= e($rowCatPrefix) ?></span><?php endif; ?><span><?= e($rowCatLeaf) ?></span></span>
                        <button type="button" class="row-edit-btn" title="Modifier"><?= icon('pencil') ?></button>
                    </div>
                    <div class="row-field-inp" hidden>
                        <div class="cat-prefix"><?= e($rowCatPrefix) ?></div>
                        <input type="text" class="row-cat-input" autocomplete="off" placeholder="— à lettrer —" value="<?= e($rowCatLeaf) ?>">
                    </div>
                    <?php else: ?>
                    <input type="text" class="row-cat-input" autocomplete="off" placeholder="— à lettrer —" value="">
                    <?php endif; ?>
                </form>
            </td>
            <?php if ($axes):
                $vents  = $ventilationsParEcr[(int) $ecr['id']] ?? [];
                $codes  = array_filter(array_map(fn($v) => $v['code'] !== '' ? e($v['code']) : e($v['libelle']), $vents));
                $dispTxt = implode(' / ', $codes);
            ?>
            <td class="axe-cell"
                data-ecr-id="<?= (int) $ecr['id'] ?>"
                data-ecr-montant="<?= (float) $ecr['montant'] ?>"
                data-ventilations="<?= e(json_encode(array_values($vents), JSON_UNESCAPED_UNICODE)) ?>">
                <div class="axe-disp">
                    <span class="axe-disp-txt muted small"><?= $dispTxt !== '' ? $dispTxt : '' ?></span>
                    <?php if ($vents): ?>
                    <button type="button" class="row-edit-btn axe-edit-btn" title="Modifier la ventilation"><?= icon('pencil') ?></button>
                    <?php endif; ?>
                    <button type="button" class="row-edit-btn axe-add-btn" title="Ajouter un axe"><?= icon('plus') ?></button>
                </div>
            </td>
            <?php endif; ?>
            <td class="actions">
                <?php if ($isManuel): ?>
                    <a class="btn ghost btn-sm icon-only" title="Modifier cette écriture"
                       href="?p=compta_ecritures<?= $qs ?>&edit=<?= (int) $ecr['id'] ?>"><?= icon('pencil') ?></a>
                <?php else: ?>
                    <a class="btn ghost btn-sm icon-only" title="Créer une règle depuis cette écriture"
                       href="?p=compta_regles&motif=<?= urlencode($ecr['texte']) ?>&compte=<?= (int) $ecr['compte_bancaire_id'] ?>"><?= icon('tag') ?></a>
                <?php endif; ?>
            </td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>


<!-- Panneau de ventilation analytique (singleton, positionné par JS) -->
<?php if ($axes): ?>
<div id="axe-panel" class="axe-panel" hidden aria-label="Ventilation analytique">
    <div id="axe-panel-rows"></div>
    <div class="axe-panel-total"><span id="axe-panel-sum">0.00</span> / <span id="axe-panel-ref"></span> CHF</div>
    <div class="axe-panel-btns">
        <button type="button" id="axe-panel-add" class="btn ghost btn-sm"><?= icon('plus') ?> Ajouter</button>
        <button type="button" id="axe-panel-save" class="btn btn-sm"><?= icon('check') ?> Enregistrer</button>
        <button type="button" id="axe-panel-cancel" class="btn ghost btn-sm">Annuler</button>
    </div>
</div>
<?php endif; ?>

<!-- Dropdown partagé pour le lettrage individuel (repositionné par JS) -->
<ul id="row-cat-list" class="cat-search-list" hidden role="listbox">
    <li data-val="" data-prefix="" data-leaf="">— à lettrer —</li>
    <li data-val="ignore" data-prefix="" data-leaf="Ne pas lettrer">Ne pas lettrer</li>
    <?php $sensCourant = null; $grpCourant = null; foreach ($feuillesSorted as $f): $fid = (int) $f['id']; $grp = $catPrefixById[$fid] ?? ''; ?>
    <?php if ($f['sens'] !== $sensCourant): $sensCourant = $f['sens']; $grpCourant = null; ?><li class="cat-search-sens"><?= $sensCourant === 'produit' ? 'Recettes' : 'Dépenses' ?></li><?php endif; ?>
    <?php if ($grp !== $grpCourant): $grpCourant = $grp; if ($grp !== ''): ?><li class="cat-search-group"><?= e($grp) ?></li><?php endif; endif; ?>
        <li data-val="<?= $fid ?>" data-prefix="<?= e($catPrefixById[$fid] ?? '') ?>" data-leaf="<?= e($catLeafById[$fid] ?? $f['chemin']) ?>"><?= e($f['chemin']) ?></li>
    <?php endforeach; ?>
</ul>

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

    // Crayon → passer en mode édition catégorie (exclut les boutons du panneau axe).
    document.addEventListener('click', e => {
        const btn = e.target.closest('.row-edit-btn:not(.axe-edit-btn):not(.axe-add-btn)');
        if (!btn) return;
        const disp = btn.closest('.row-field-disp');
        if (!disp) return;
        const inp = disp.nextElementSibling;
        if (!inp) return;
        disp.hidden = true;
        inp.hidden = false;
        inp.querySelector('input[type="text"]')?.focus();
    });



    // Recherche instantanée (insensible à la casse et aux accents).
    const search = document.getElementById('compta-search');
    const count  = document.getElementById('search-count');
    const rows   = Array.from(document.querySelectorAll('.compta-lettrage tbody tr'));
    const norm = s => s.toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '');
    const texteLigne = r => {
        const date = r.querySelector('td.nowrap')?.textContent || '';
        const cpt  = r.querySelector('.compte-cell')?.textContent || '';
        const txt  = r.querySelector('.texte-cell')?.textContent || '';
        const mt   = r.querySelector('td.num')?.textContent || '';
        const cat  = r.querySelector('.row-cat-input')?.value || '';
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

// Bouton "Nouvelle écriture manuelle" — affiche/masque le formulaire
(function () {
    const btn  = document.getElementById('btn-new-ecr');
    const form = document.getElementById('ecr-manuel-form');
    if (!btn || !form) return;
    btn.addEventListener('click', () => {
        form.hidden = !form.hidden;
        if (!form.hidden) form.querySelector('input[type="date"], input[name="texte"]')?.focus();
    });
})();

// Cat-search — formulaire écriture manuelle
(function () {
    const wrap = document.querySelector('.form-cat-search');
    if (!wrap) return;
    const input    = wrap.querySelector('.cat-search-input');
    const hidden   = wrap.querySelector('.cat-search-val');
    const list     = wrap.querySelector('.cat-search-list');
    const items    = Array.from(list.querySelectorAll('li:not(.cat-search-group):not(.cat-search-sens)'));
    const groups   = Array.from(list.querySelectorAll('.cat-search-group'));
    const sensHdrs = Array.from(list.querySelectorAll('.cat-search-sens'));
    const norm = s => s.toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '');
    function filterGroups() {
        groups.forEach(g => { let s = g.nextElementSibling, v = false; while (s && !s.classList.contains('cat-search-group') && !s.classList.contains('cat-search-sens')) { if (!s.hidden) v = true; s = s.nextElementSibling; } g.hidden = !v; });
        sensHdrs.forEach(h => { let s = h.nextElementSibling, v = false; while (s && !s.classList.contains('cat-search-sens')) { if (!s.hidden) v = true; s = s.nextElementSibling; } h.hidden = !v; });
    }
    function filter(q) { const nq = norm(q); items.forEach(li => { li.hidden = nq !== '' && !norm(li.textContent).includes(nq); }); filterGroups(); }
    input.addEventListener('focus', () => { filter(input.value); list.hidden = false; });
    input.addEventListener('input', () => { filter(input.value); list.hidden = false; });
    input.addEventListener('blur',  () => { setTimeout(() => { list.hidden = true; const cur = items.find(li => li.dataset.val === hidden.value); input.value = cur && cur.dataset.val !== '' ? cur.textContent : ''; }, 150); });
    items.forEach(li => { li.addEventListener('mousedown', e => { e.preventDefault(); hidden.value = li.dataset.val; input.value = li.dataset.val !== '' ? li.textContent : ''; list.hidden = true; }); });
})();

// Dropdown cherchable — bulk-lettrage (dropdown propre à la barre)
(function () {
    const wrap = document.querySelector('.bulk-cat-search');
    if (!wrap) return;
    const input    = wrap.querySelector('.cat-search-input');
    const hidden   = wrap.querySelector('.cat-search-val');
    const list     = wrap.querySelector('.cat-search-list');
    const items    = Array.from(list.querySelectorAll('li:not(.cat-search-group):not(.cat-search-sens)'));
    const groups   = Array.from(list.querySelectorAll('.cat-search-group'));
    const sensHdrs = Array.from(list.querySelectorAll('.cat-search-sens'));
    const norm = s => s.toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '');
    const initItem = items.find(li => li.dataset.val === hidden.value);
    if (initItem) input.value = initItem.textContent;
    function filterGroups() {
        groups.forEach(g => { let s = g.nextElementSibling, v = false; while (s && !s.classList.contains('cat-search-group') && !s.classList.contains('cat-search-sens')) { if (!s.hidden) v = true; s = s.nextElementSibling; } g.hidden = !v; });
        sensHdrs.forEach(h => { let s = h.nextElementSibling, v = false; while (s && !s.classList.contains('cat-search-sens')) { if (!s.hidden) v = true; s = s.nextElementSibling; } h.hidden = !v; });
    }
    function filter(q) { const nq = norm(q); items.forEach(li => { li.hidden = nq !== '' && !norm(li.textContent).includes(nq); }); filterGroups(); }
    input.addEventListener('focus', () => { filter(input.value); list.hidden = false; });
    input.addEventListener('input', () => { filter(input.value); list.hidden = false; });
    input.addEventListener('blur',  () => { setTimeout(() => { list.hidden = true; const cur = items.find(li => li.dataset.val === hidden.value); input.value = cur ? cur.textContent : ''; }, 150); });
    items.forEach(li => { li.addEventListener('mousedown', e => { e.preventDefault(); hidden.value = li.dataset.val; input.value = li.textContent; list.hidden = true; }); });
})();

// Dropdown partagé — lettrage individuel par ligne
(function () {
    const list = document.getElementById('row-cat-list');
    if (!list) return;
    const items    = Array.from(list.querySelectorAll('li:not(.cat-search-group):not(.cat-search-sens)'));
    const groups   = Array.from(list.querySelectorAll('.cat-search-group'));
    const sensHdrs = Array.from(list.querySelectorAll('.cat-search-sens'));
    const norm = s => s.toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '');
    let activeInput = null, activeHidden = null, activeForm = null, activePrefix = null;

    function filterGroups() {
        groups.forEach(g => { let s = g.nextElementSibling, v = false; while (s && !s.classList.contains('cat-search-group') && !s.classList.contains('cat-search-sens')) { if (!s.hidden) v = true; s = s.nextElementSibling; } g.hidden = !v; });
        sensHdrs.forEach(h => { let s = h.nextElementSibling, v = false; while (s && !s.classList.contains('cat-search-sens')) { if (!s.hidden) v = true; s = s.nextElementSibling; } h.hidden = !v; });
    }
    function filter(q) { const nq = norm(q); items.forEach(li => { li.hidden = nq !== '' && !norm(li.textContent).includes(nq); }); filterGroups(); }
    function position(input) {
        const r = input.getBoundingClientRect();
        list.style.top    = (r.bottom + window.scrollY + 2) + 'px';
        list.style.left   = r.left + 'px';
        list.style.width  = Math.max(r.width, 260) + 'px';
    }
    function applyLeaf(li) {
        if (!activeInput) return;
        const hasVal = li.dataset.val !== '';
        activeInput.value = hasVal ? li.dataset.leaf : '';
        if (activePrefix) activePrefix.textContent = hasVal ? (li.dataset.prefix || '') : '';
    }

    document.querySelectorAll('.row-cat-input').forEach(input => {
        const form   = input.closest('form');
        const hidden = form.querySelector('.row-cat-val');
        const pfx    = form.querySelector('.cat-prefix');
        input.addEventListener('focus', () => {
            activeInput = input; activeHidden = hidden; activeForm = form; activePrefix = pfx;
            input.value = ''; // vider pour la recherche
            filter(''); list.hidden = false; position(input);
        });
        input.addEventListener('input', () => { filter(input.value); list.hidden = false; position(input); });
        input.addEventListener('blur',  () => {
            setTimeout(() => {
                list.hidden = true;
                const cur = items.find(li => li.dataset.val === (activeHidden?.value ?? ''));
                if (cur) applyLeaf(cur); else if (activeInput) { activeInput.value = ''; if (activePrefix) activePrefix.textContent = ''; }
                const inp = input.closest('.row-field-inp');
                if (inp) { const disp = inp.previousElementSibling; if (disp?.classList.contains('row-field-disp')) { inp.hidden = true; disp.hidden = false; } }
            }, 150);
        });
    });

    items.forEach(li => {
        li.addEventListener('mousedown', e => {
            e.preventDefault();
            if (!activeHidden || !activeInput || !activeForm) return;
            activeHidden.value = li.dataset.val;
            applyLeaf(li);
            list.hidden = true;
            activeForm.submit();
        });
    });

    document.addEventListener('mousedown', e => {
        if (!list.hidden && !list.contains(e.target) && e.target !== activeInput) list.hidden = true;
    });
    window.addEventListener('scroll', () => { if (!list.hidden && activeInput) position(activeInput); }, { passive: true });
})();

// Bulk axe — injecte les IDs cochés dans le formulaire axe avant soumission
(function () {
    const axeForm = document.getElementById('axe-bulkform');
    if (!axeForm) return;
    axeForm.addEventListener('submit', e => {
        axeForm.querySelectorAll('input[name="ids[]"]').forEach(el => el.remove());
        document.querySelectorAll('.row-check:checked').forEach(cb => {
            const inp = document.createElement('input');
            inp.type = 'hidden'; inp.name = 'ids[]'; inp.value = cb.value;
            axeForm.appendChild(inp);
        });
        if (!document.querySelectorAll('.row-check:checked').length) {
            e.preventDefault();
        }
    });
})();

// Panneau de ventilation analytique (multi-axe par écriture)
(function () {
    const panel  = document.getElementById('axe-panel');
    if (!panel) return;
    const rowsEl = document.getElementById('axe-panel-rows');
    const sumEl  = document.getElementById('axe-panel-sum');
    const refEl  = document.getElementById('axe-panel-ref');
    const addBtn = document.getElementById('axe-panel-add');
    const saveBtn = document.getElementById('axe-panel-save');
    const cancelBtn = document.getElementById('axe-panel-cancel');
    const CSRF   = <?= json_encode(csrf_token()) ?>;
    const AXES   = <?= json_encode(array_map(fn($a) => ['id' => (int)$a['id'], 'label' => ($a['code'] !== '' ? $a['code'] : $a['libelle'])], $axes), JSON_UNESCAPED_UNICODE) ?>;

    let currentCell = null;

    function makeRow(v) {
        const d = document.createElement('div');
        d.className = 'axe-panel-row';

        const sel = document.createElement('select');
        sel.className = 'vent-axe';
        sel.appendChild(new Option('— Choisir —', ''));
        AXES.forEach(a => {
            const o = new Option(a.label, String(a.id));
            if (v && a.id === v.axe_id) o.selected = true;
            sel.appendChild(o);
        });

        const inp = document.createElement('input');
        inp.type = 'number'; inp.className = 'vent-mont'; inp.step = '0.01';
        inp.placeholder = 'Montant';
        if (v) inp.value = v.montant;
        inp.addEventListener('input', updateSum);

        const del = document.createElement('button');
        del.type = 'button'; del.className = 'vent-del'; del.title = 'Supprimer';
        del.innerHTML = <?= json_encode(icon('x')) ?>; // SVG trusted, généré côté serveur
        del.addEventListener('click', () => { d.remove(); updateSum(); });

        d.append(sel, inp, del);
        return d;
    }

    function updateSum() {
        const total = Array.from(rowsEl.querySelectorAll('.vent-mont'))
            .reduce((s, i) => s + (parseFloat(i.value) || 0), 0);
        sumEl.textContent = total.toFixed(2);
        const ref = parseFloat(currentCell?.dataset.ecrMontant || 0);
        sumEl.style.color = Math.abs(Math.abs(total) - Math.abs(ref)) < 0.005 ? '' : 'var(--amber)';
    }

    function openPanel(cell, addNew) {
        currentCell = cell;
        rowsEl.innerHTML = '';
        const vents = JSON.parse(cell.dataset.ventilations || '[]');
        vents.forEach(v => rowsEl.appendChild(makeRow(v)));
        if (addNew || vents.length === 0) rowsEl.appendChild(makeRow(null));
        const ref = parseFloat(cell.dataset.ecrMontant || 0);
        refEl.textContent = Math.abs(ref).toFixed(2);
        updateSum();
        // Positionnement sous la cellule, clamping horizontal + vertical
        const r = cell.getBoundingClientRect();
        panel.hidden = false; // doit être visible avant offsetHeight
        const pw = panel.offsetWidth, ph = panel.offsetHeight;
        const left = Math.max(8, Math.min(r.left, window.innerWidth - pw - 8));
        let top = r.bottom + window.scrollY + 4;
        if (r.bottom + ph + 4 > window.innerHeight) {
            top = r.top + window.scrollY - ph - 4; // positionner au-dessus si manque de place
        }
        panel.style.left = left + 'px';
        panel.style.top  = top + 'px';
        rowsEl.querySelector('select')?.focus();
    }

    function closePanel() {
        panel.hidden = true;
        currentCell = null;
    }

    async function savePanel() {
        if (!currentCell) return;
        const ecrId = currentCell.dataset.ecrId;
        const rows  = Array.from(rowsEl.querySelectorAll('.axe-panel-row'));
        const fd = new FormData();
        fd.append('csrf', CSRF);
        fd.append('ecriture_id', ecrId);
        rows.forEach(r => {
            const aId = r.querySelector('.vent-axe').value;
            const mt  = r.querySelector('.vent-mont').value;
            if (aId) { fd.append('axe_id[]', aId); fd.append('montant[]', mt || '0'); }
        });
        saveBtn.disabled = true;
        try {
            const res = await fetch('?p=compta_ventilation_save', { method: 'POST', body: fd });
            const data = await res.json();
            if (data.ok) {
                currentCell.dataset.ventilations = JSON.stringify(data.ventilations);
                const codes = data.ventilations.map(v => v.code || v.libelle).join(' / ');
                const disp = currentCell.querySelector('.axe-disp-txt');
                disp.textContent = codes;
                // Afficher/masquer le crayon
                let editBtn = currentCell.querySelector('.axe-edit-btn');
                if (data.ventilations.length > 0 && !editBtn) {
                    editBtn = document.createElement('button');
                    editBtn.type = 'button';
                    editBtn.className = 'row-edit-btn axe-edit-btn';
                    editBtn.title = 'Modifier la ventilation';
                    editBtn.innerHTML = <?= json_encode(icon('pencil')) ?>;
                    currentCell.querySelector('.axe-disp').insertBefore(editBtn, currentCell.querySelector('.axe-add-btn'));
                } else if (data.ventilations.length === 0 && editBtn) {
                    editBtn.remove();
                }
                closePanel();
            }
        } finally {
            saveBtn.disabled = false;
        }
    }

    document.addEventListener('click', e => {
        if (e.target.closest('.axe-edit-btn')) {
            openPanel(e.target.closest('.axe-cell'), false);
        } else if (e.target.closest('.axe-add-btn')) {
            openPanel(e.target.closest('.axe-cell'), true);
        } else if (!panel.hidden && !panel.contains(e.target)) {
            closePanel();
        }
    });
    addBtn.addEventListener('click', () => { rowsEl.appendChild(makeRow(null)); updateSum(); rowsEl.lastElementChild.querySelector('select')?.focus(); });
    saveBtn.addEventListener('click', savePanel);
    cancelBtn.addEventListener('click', closePanel);
    document.addEventListener('keydown', e => { if (e.key === 'Escape' && !panel.hidden) closePanel(); });
})();
</script>
<?php endif; ?>
<script>
// Cat-search — filtre catégorie (soumet le form GET à la sélection)
// Toujours rendu, même quand la liste d'écritures est vide.
(function () {
    const wrap = document.querySelector('.filtre-cat-search');
    if (!wrap) return;
    const input  = wrap.querySelector('.cat-search-input');
    const hidden = wrap.querySelector('.cat-search-val');
    const list   = wrap.querySelector('.cat-search-list');
    const items    = Array.from(list.querySelectorAll('li:not(.cat-search-group):not(.cat-search-sens)'));
    const groups   = Array.from(list.querySelectorAll('.cat-search-group'));
    const sensHdrs = Array.from(list.querySelectorAll('.cat-search-sens'));
    const norm     = s => s.toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '');
    function filter(q) {
        const nq = norm(q);
        items.forEach(li => { li.hidden = nq !== '' && !norm(li.textContent).includes(nq); });
        groups.forEach(g => { let s = g.nextElementSibling, v = false; while (s && !s.classList.contains('cat-search-group') && !s.classList.contains('cat-search-sens')) { if (!s.hidden) v = true; s = s.nextElementSibling; } g.hidden = !v; });
        sensHdrs.forEach(h => { let s = h.nextElementSibling, v = false; while (s && !s.classList.contains('cat-search-sens')) { if (!s.hidden) v = true; s = s.nextElementSibling; } h.hidden = !v; });
    }
    input.addEventListener('focus', () => { input.value = ''; filter(''); list.hidden = false; });
    input.addEventListener('input', () => { filter(input.value); list.hidden = false; });
    input.addEventListener('blur',  () => {
        setTimeout(() => {
            list.hidden = true;
            const cur = items.find(li => li.dataset.val === hidden.value);
            input.value = cur ? (cur.dataset.val !== '' ? cur.textContent.trim() : '') : '';
        }, 150);
    });
    items.forEach(li => {
        li.addEventListener('mousedown', e => {
            e.preventDefault();
            hidden.value = li.dataset.val;
            input.value  = li.dataset.val !== '' ? li.textContent : '';
            list.hidden  = true;
            wrap.closest('form').submit();
        });
    });
})();
</script>
