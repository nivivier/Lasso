<?php
/** @var array $comptes */ /** @var int $compteId */ /** @var int $annee */ /** @var array $annees */
/** @var string $categorieFilter */ /** @var string $axeFilter */ /** @var array $ecritures */
/** @var array $ventilationsParEcr */ /** @var array $feuilles */ /** @var array $axes */
/** @var ?string $rules */ /** @var ?array $editEcr */ /** @var bool $openNew */
/** @var ?int $bulkCount */ /** @var bool $okAnnule */ /** @var string $recherche */ /** @var bool $modeClient */
/** @var string $pgRoute */ /** @var array $pgParams */ /** @var int $pgPage */ /** @var int $pgTaille */ /** @var int $pgTotal */

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
<?php $actionUrl = '?p=compta_ecritures'; require __DIR__ . '/_bulk_undo_flash.php'; ?>
<div class="page-head-band">
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
        <a href="?p=compta_regles" class="btn ghost btn-sm btn-compact"><?= icon('settings') ?> <span>Lettrage auto<span class="lbl">matique</span></span></a>
        <button type="button" id="btn-new-ecr" class="btn ghost btn-sm btn-compact"><?= icon('plus') ?> Écriture manuelle</button>
        <a href="?p=compta_import" class="btn"><?= icon('upload') ?><span class="lbl"> Importer</span></a>
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
            <select name="categorie" onchange="this.form.submit()">
                <option value="" <?= $categorieFilter === '' ? 'selected' : '' ?>>Toutes</option>
                <option value="a_lettrer" <?= $categorieFilter === 'a_lettrer' ? 'selected' : '' ?>>— À lettrer —</option>
                <option value="ignore" <?= $categorieFilter === 'ignore' ? 'selected' : '' ?>>— Ne pas lettrer —</option>
                <?php foreach ($categoriesArbre as $c): $cid = (int) $c['id']; ?>
                    <option value="<?= $cid ?>" <?= $categorieFilter === (string) $cid ? 'selected' : '' ?>>
                        <?= str_repeat("\u{00A0}\u{00A0}", (int) $c['profondeur']) ?><?= e($c['libelle']) ?><?= !empty($c['a_enfants']) ? ' (et sous-catégories)' : '' ?>
                    </option>
                <?php endforeach; ?>
            </select>
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
        <label class="search-label">
            <input type="search" name="q" id="compta-search" placeholder="Texte, montant, catégorie…" autocomplete="off" aria-label="Rechercher" value="<?= e($recherche) ?>">
        </label>
    </form>
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
                <button type="submit" form="del-ecr-form" class="btn danger"><?= icon('trash') ?> Supprimer</button>
            <?php endif; ?>
        </div>
    </form>
    <?php if ($isEdit): ?>
    <form id="del-ecr-form" method="post" action="?p=compta_ecritures<?= $qs ?>" onsubmit="return confirm('Supprimer cette écriture ?');" hidden>
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="section" value="delete_manual">
        <input type="hidden" name="id" value="<?= (int) $editEcr['id'] ?>">
    </form>
    <?php endif; ?>
</div>

<?php if (!$comptes): ?>
    <p class="muted">Aucun compte bancaire. Commencez par en <a href="?p=compta_comptes">créer un</a> puis <a href="?p=compta_import">importer</a> un export.</p>
<?php elseif (!$ecritures): ?>
    <p class="muted">Aucune écriture pour ce filtre.</p>
<?php else: ?>





<div class="bulk-bar" id="bulk-bar" hidden>
    <form method="post" id="bulkform" action="?p=compta_ecritures<?= $qs ?>">
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
        <select name="section" id="bulk-action" class="inline-year-select">
            <option value="">— Choisir une action —</option>
            <option value="lettrer">Modifier la catégorie</option>
            <?php if ($axes): ?><option value="axer">Modifier l'axe</option><?php endif; ?>
        </select>

        <span class="bulk-field" data-for="lettrer" hidden>
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
        </span>
        <?php if ($axes): ?>
        <span class="bulk-field" data-for="axer" hidden>
            <select name="axe_analytique_id" class="inline-year-select">
                <option value="">— Retirer —</option>
                <?php foreach ($axes as $ax): ?>
                    <option value="<?= (int) $ax['id'] ?>"><?= e($axeLabel($ax)) ?></option>
                <?php endforeach; ?>
            </select>
        </span>
        <?php endif; ?>

        <button type="submit" class="btn" id="bulk-submit" disabled>Modifier la sélection</button>
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
            <?php if ($axes): ?><th>Axe</th><?php endif; ?>
            <th></th>
        </tr>
    </thead>
    <tbody>
    <?php
    $prevMois = null;
    $nbCols = 6 + ($compteId === 0 ? 1 : 0) + ($axes ? 1 : 0);
    // Options du select inline pour la colonne axe (construit une fois, réutilisé par toutes les lignes)
    $axeOptsHtml = '';
    foreach ($axes as $ax) {
        $axeOptsHtml .= '<option value="' . (int) $ax['id'] . '">' . e($ax['code'] ?: $ax['libelle']) . '</option>';
    }
    foreach ($ecritures as $ecr): $neg = (float) $ecr['montant'] < 0;
        $moisCle = substr((string) $ecr['date_op'], 0, 7);
        if ($moisCle !== $prevMois):
            $prevMois = $moisCle;
    ?>
        <tr class="ecr-mois-sep"><td colspan="<?= $nbCols ?>">
            <?= e(mois_nom((int) substr($moisCle, 5, 2))) . ' ' . substr($moisCle, 0, 4) ?>
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
            <td class="texte-cell" title="<?= e($ecr['texte']) ?>">
                <?php if (!empty($ecr['facture_id'])): ?>
                    <a class="ecr-facture-lien" href="<?= e(url_avec_retour('?p=facture&id=' . (int) $ecr['facture_id'], 'compta_ecritures')) ?>" title="Voir la facture liée"><?= icon('receipt-swiss-franc') ?></a>
                <?php endif; ?>
                <span class="texte-cell-txt" data-summary="<?= e(resumer_texte_postfinance($ecr['texte'])) ?>"><?= e(resumer_texte_postfinance($ecr['texte'])) ?></span>
            </td>
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
                $vents   = $ventilationsParEcr[(int) $ecr['id']] ?? [];
                $nVents  = count($vents);
                $dispTxt = implode(' / ', array_map(fn($v) => $v['code'] !== '' ? e($v['code']) : e($v['libelle']), $vents));
            ?>
            <td class="axe-cell"
                data-ecr-id="<?= (int) $ecr['id'] ?>"
                data-ecr-montant="<?= (float) $ecr['montant'] ?>"
                data-ventilations="<?= e(json_encode(array_values($vents), JSON_UNESCAPED_UNICODE)) ?>">
                <div class="axe-disp">
                    <?php if ($nVents === 0): ?>
                        <select class="axe-inline-sel" title="Axe analytique"><option value="">— Axe —</option><?= $axeOptsHtml ?></select>
                        <button type="button" class="row-edit-btn axe-add-btn" title="Ventilation multi-axe"><?= icon('plus') ?></button>
                    <?php elseif ($nVents === 1): ?>
                        <span class="axe-disp-txt muted small"><?= $dispTxt ?></span>
                        <button type="button" class="row-edit-btn axe-edit-btn" title="Modifier l'axe"><?= icon('pencil') ?></button>
                        <button type="button" class="row-edit-btn axe-add-btn" title="Ventilation multi-axe"><?= icon('plus') ?></button>
                    <?php else: ?>
                        <span class="axe-disp-txt muted small"><?= $dispTxt ?></span>
                        <button type="button" class="row-edit-btn axe-edit-btn" title="Modifier la ventilation"><?= icon('pencil') ?></button>
                    <?php endif; ?>
                </div>
            </td>
            <?php endif; ?>
            <td class="actions">
                <?php if ($isManuel): ?>
                    <a class="btn ghost btn-sm icon-only" title="Modifier cette écriture" aria-label="Modifier cette écriture"
                       href="?p=compta_ecritures<?= $qs ?>&edit=<?= (int) $ecr['id'] ?>"><?= icon('pencil') ?></a>
                <?php else: ?>
                    <a class="btn ghost btn-sm icon-only" title="Créer une règle depuis cette écriture" aria-label="Créer une règle depuis cette écriture"
                       href="?p=compta_regles&motif=<?= urlencode($ecr['texte']) ?>&compte=<?= (int) $ecr['compte_bancaire_id'] ?>"><?= icon('tag') ?></a>
                <?php endif; ?>
            </td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
</div>
<?php require __DIR__ . '/' . ($modeClient ? '_pagination_client.php' : '_pagination.php'); ?>


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

    // Action choisie → affiche le champ correspondant et adapte le libellé du bouton.
    const bulkAction = document.getElementById('bulk-action');
    const bulkSubmit = document.getElementById('bulk-submit');
    const bulkFields = document.querySelectorAll('.bulk-field');
    function syncBulkAction() {
        bulkFields.forEach(f => { f.hidden = f.dataset.for !== bulkAction.value; });
        bulkSubmit.disabled = bulkAction.value === '';
    }
    if (bulkAction) { bulkAction.addEventListener('change', syncBulkAction); syncBulkAction(); }

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



    // Recherche : voir lassoRechercheServeur() (assets/app.js) — paginée côté
    // serveur, sinon une recherche ne porterait que sur la page déjà chargée.
    // En dessous du seuil client (lib/helpers.php), lassoListeClient() prend
    // le relais entièrement en JS (voir aussi employes.php pour le même motif).
    <?php if ($modeClient): ?>
    lassoListeClient({
        tableSelector: '.compta-lettrage',
        searchInputSelector: '#compta-search',
        separatorSelector: '.ecr-mois-sep',
    });
    <?php else: ?>
    lassoRechercheServeur(document.getElementById('compta-search'));
    <?php endif; ?>
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

// Cat-search — formulaire écriture manuelle (texte déjà pré-rempli côté serveur)
(function () {
    const wrap = document.querySelector('.form-cat-search');
    if (wrap) lassoInitCatSearch(wrap, { groupsFilter: true });
})();

// Dropdown cherchable — bulk-lettrage (dropdown propre à la barre)
(function () {
    const wrap = document.querySelector('.bulk-cat-search');
    if (wrap) lassoInitCatSearch(wrap, { groupsFilter: true, hydrateInitial: true, showPlaceholderText: true });
})();

// Dropdown partagé — lettrage individuel par ligne
(function () {
    const list = document.getElementById('row-cat-list');
    if (!list) return;
    const items    = Array.from(list.querySelectorAll('li:not(.cat-search-group):not(.cat-search-sens)'));
    const groups   = Array.from(list.querySelectorAll('.cat-search-group'));
    const sensHdrs = Array.from(list.querySelectorAll('.cat-search-sens'));
    let activeInput = null, activeHidden = null, activeForm = null, activePrefix = null;

    function filterGroups() {
        groups.forEach(g => { let s = g.nextElementSibling, v = false; while (s && !s.classList.contains('cat-search-group') && !s.classList.contains('cat-search-sens')) { if (!s.hidden) v = true; s = s.nextElementSibling; } g.hidden = !v; });
        sensHdrs.forEach(h => { let s = h.nextElementSibling, v = false; while (s && !s.classList.contains('cat-search-sens')) { if (!s.hidden) v = true; s = s.nextElementSibling; } h.hidden = !v; });
    }
    function filter(q) { const nq = lassoNorm(q); items.forEach(li => { li.hidden = nq !== '' && !lassoNorm(li.textContent).includes(nq); }); filterGroups(); }
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

// Colonne axe analytique : select inline (0 axe), inline edit (1 axe), panneau multi-axe (≥2)
(function () {
    const panel     = document.getElementById('axe-panel');
    if (!panel) return;
    const rowsEl    = document.getElementById('axe-panel-rows');
    const sumEl     = document.getElementById('axe-panel-sum');
    const refEl     = document.getElementById('axe-panel-ref');
    const addBtn    = document.getElementById('axe-panel-add');
    const saveBtn   = document.getElementById('axe-panel-save');
    const cancelBtn = document.getElementById('axe-panel-cancel');
    const CSRF        = <?= json_encode(csrf_token()) ?>;
    const AXES        = <?= json_encode(array_map(fn($a) => ['id' => (int)$a['id'], 'label' => ($a['code'] !== '' ? $a['code'] : $a['libelle'])], $axes), JSON_UNESCAPED_UNICODE) ?>;
    const PENCIL_ICON = <?= json_encode(icon('pencil')) ?>;
    const PLUS_ICON   = <?= json_encode(icon('plus')) ?>;

    let currentCell  = null;
    let inlineSaving = false;

    // ---- Helpers cellule ----

    function buildInlineSel(selectedAxeId) {
        const sel = document.createElement('select');
        sel.className = 'axe-inline-sel'; sel.title = 'Axe analytique';
        sel.appendChild(new Option('— Axe —', ''));
        AXES.forEach(a => {
            const o = new Option(a.label, String(a.id));
            if (selectedAxeId && a.id === selectedAxeId) o.selected = true;
            sel.appendChild(o);
        });
        return sel;
    }

    function buildPlusBtn(cell) {
        const btn = document.createElement('button');
        btn.type = 'button'; btn.className = 'row-edit-btn axe-add-btn';
        btn.title = 'Ventilation multi-axe'; btn.innerHTML = PLUS_ICON;
        btn.addEventListener('click', () => openPanel(cell, true));
        return btn;
    }

    function buildPencilBtn(cell, mode) {
        const btn = document.createElement('button');
        btn.type = 'button'; btn.className = 'row-edit-btn axe-edit-btn';
        btn.title = mode === 'inline' ? "Modifier l'axe" : 'Modifier la ventilation';
        btn.innerHTML = PENCIL_ICON;
        btn.addEventListener('click', () => mode === 'inline' ? startInlineEdit(cell) : openPanel(cell, false));
        return btn;
    }

    // Reconstruit le contenu de .axe-disp selon le nombre de ventilations.
    function updateCellDOM(cell, ventilations) {
        cell.dataset.ventilations = JSON.stringify(ventilations);
        const disp = cell.querySelector('.axe-disp');
        while (disp.firstChild) disp.removeChild(disp.firstChild);
        const n = ventilations.length;
        if (n === 0) {
            const sel = buildInlineSel(null);
            // Pas de listener direct ici : géré par la délégation document.change.
            disp.append(sel, buildPlusBtn(cell));
        } else if (n === 1) {
            const txt = document.createElement('span');
            txt.className = 'axe-disp-txt muted small';
            txt.textContent = ventilations[0].code || ventilations[0].libelle;
            disp.append(txt, buildPencilBtn(cell, 'inline'), buildPlusBtn(cell));
        } else {
            const txt = document.createElement('span');
            txt.className = 'axe-disp-txt muted small';
            txt.textContent = ventilations.map(v => v.code || v.libelle).join(' / ');
            disp.append(txt, buildPencilBtn(cell, 'panel'));
        }
    }

    // Passe la cellule 1-axe en mode édition inline.
    function startInlineEdit(cell) {
        const vents = JSON.parse(cell.dataset.ventilations || '[]');
        const currentAxeId = vents.length > 0 ? vents[0].axe_id : null;
        const disp = cell.querySelector('.axe-disp');
        while (disp.firstChild) disp.removeChild(disp.firstChild);

        const sel = buildInlineSel(currentAxeId);
        sel.addEventListener('change', async () => {
            inlineSaving = true;
            await saveInline(cell, sel.value);
            inlineSaving = false;
        });
        // Restaure l'état précédent si on quitte sans changer
        sel.addEventListener('blur', () => {
            setTimeout(() => { if (!inlineSaving && disp.contains(sel)) updateCellDOM(cell, vents); }, 150);
        });
        sel.addEventListener('keydown', e => { if (e.key === 'Escape') updateCellDOM(cell, vents); });
        disp.appendChild(sel);
        sel.focus();
    }

    // Sauvegarde un axe unique via AJAX (montant = abs(écriture)).
    async function saveInline(cell, axeId) {
        const prevVents = JSON.parse(cell.dataset.ventilations || '[]');
        const montant   = Math.abs(parseFloat(cell.dataset.ecrMontant || '0'));
        const fd = new FormData();
        fd.append('csrf', CSRF);
        fd.append('ecriture_id', cell.dataset.ecrId);
        if (axeId) { fd.append('axe_id[]', axeId); fd.append('montant[]', montant.toFixed(2)); }
        try {
            const data = await fetch('?p=compta_ventilation_save', { method: 'POST', body: fd }).then(r => r.json());
            updateCellDOM(cell, data.ok ? data.ventilations : prevVents);
        } catch (_) { updateCellDOM(cell, prevVents); }
    }

    // ---- Panneau multi-axe ----

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
        if (v) inp.value = Math.abs(parseFloat(v.montant)).toFixed(2);
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
        refEl.textContent = Math.abs(parseFloat(cell.dataset.ecrMontant || 0)).toFixed(2);
        updateSum();
        const r = cell.getBoundingClientRect();
        panel.hidden = false; // doit être visible avant offsetHeight
        const pw = panel.offsetWidth, ph = panel.offsetHeight;
        const left = Math.max(8, Math.min(r.left, window.innerWidth - pw - 8));
        let top = r.bottom + window.scrollY + 4;
        if (r.bottom + ph + 4 > window.innerHeight) top = r.top + window.scrollY - ph - 4;
        panel.style.left = left + 'px'; panel.style.top = top + 'px';
        rowsEl.querySelector('select')?.focus();
    }

    function closePanel() { panel.hidden = true; currentCell = null; }

    async function savePanel() {
        if (!currentCell) return;
        const fd = new FormData();
        fd.append('csrf', CSRF);
        fd.append('ecriture_id', currentCell.dataset.ecrId);
        rowsEl.querySelectorAll('.axe-panel-row').forEach(r => {
            const aId = r.querySelector('.vent-axe').value;
            const mt  = r.querySelector('.vent-mont').value;
            if (aId) { fd.append('axe_id[]', aId); fd.append('montant[]', mt || '0'); }
        });
        saveBtn.disabled = true;
        try {
            const data = await fetch('?p=compta_ventilation_save', { method: 'POST', body: fd }).then(r => r.json());
            if (data.ok) { updateCellDOM(currentCell, data.ventilations); closePanel(); }
        } finally { saveBtn.disabled = false; }
    }

    // ---- Événements ----

    // Select inline (case 0 axe) — PHP-rendu et JS-recréé après save.
    // !inlineSaving évite d'intercepter le select d'édition de startInlineEdit.
    document.addEventListener('change', e => {
        const sel = e.target.closest('.axe-inline-sel');
        if (sel?.closest('.axe-cell') && sel.value && !inlineSaving) saveInline(sel.closest('.axe-cell'), sel.value);
    });

    // Crayon (inline edit si ≤1 axe, panneau si ≥2) + plus (panneau)
    document.addEventListener('click', e => {
        const editBtn = e.target.closest('.axe-edit-btn');
        if (editBtn) {
            const cell  = editBtn.closest('.axe-cell');
            const vents = JSON.parse(cell.dataset.ventilations || '[]');
            vents.length <= 1 ? startInlineEdit(cell) : openPanel(cell, false);
            return;
        }
        const addBtn2 = e.target.closest('.axe-add-btn');
        if (addBtn2?.closest('.axe-cell')) { openPanel(addBtn2.closest('.axe-cell'), true); return; }
        if (!panel.hidden && !panel.contains(e.target)) closePanel();
    });

    addBtn.addEventListener('click', () => { rowsEl.appendChild(makeRow(null)); updateSum(); rowsEl.lastElementChild.querySelector('select')?.focus(); });
    saveBtn.addEventListener('click', savePanel);
    cancelBtn.addEventListener('click', closePanel);
    document.addEventListener('keydown', e => { if (e.key === 'Escape' && !panel.hidden) closePanel(); });
})();
</script>
<?php endif; ?>
