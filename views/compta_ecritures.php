<?php
/** @var array $comptes */ /** @var int $compteId */ /** @var int $annee */ /** @var array $annees */
/** @var string $statut */ /** @var array $ecritures */ /** @var array $feuilles */
/** @var int $nbALettrer */ /** @var ?string $rules */

// Map id → chemin pour initialiser les inputs individuels.
$cheminById = [];
foreach ($feuilles as $f) { $cheminById[(int) $f['id']] = $f['chemin']; }
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
        <div class="cat-search bulk-cat-search">
            <input type="text" class="cat-search-input" placeholder="Catégorie ou retirer le lettrage…" autocomplete="off">
            <input type="hidden" name="plan_compte_id" class="cat-search-val" value="">
            <ul class="cat-search-list" hidden role="listbox">
                <li data-val="">— Retirer le lettrage —</li>
                <?php foreach ($feuilles as $f): ?>
                    <li data-val="<?= (int) $f['id'] ?>"><?= e($f['chemin']) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
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
                    <input type="text" class="row-cat-input" autocomplete="off" placeholder="— à lettrer —"
                           value="<?= e($cheminById[(int) ($ecr['plan_compte_id'] ?? 0)] ?? '') ?>">
                    <input type="hidden" name="plan_compte_id" class="row-cat-val" value="<?= e($ecr['plan_compte_id'] ?? '') ?>">
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

<!-- Dropdown partagé pour le lettrage individuel (repositionné par JS) -->
<ul id="row-cat-list" class="cat-search-list" hidden role="listbox">
    <li data-val="">— à lettrer —</li>
    <?php foreach ($feuilles as $f): ?>
        <li data-val="<?= (int) $f['id'] ?>"><?= e($f['chemin']) ?></li>
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
// Dropdown cherchable — bulk-lettrage (dropdown propre à la barre)
(function () {
    const wrap = document.querySelector('.bulk-cat-search');
    if (!wrap) return;
    const input  = wrap.querySelector('.cat-search-input');
    const hidden = wrap.querySelector('.cat-search-val');
    const list   = wrap.querySelector('.cat-search-list');
    const items  = Array.from(list.querySelectorAll('li'));
    const norm = s => s.toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '');
    // Sélection initiale : "— Retirer le lettrage —"
    const initItem = items.find(li => li.dataset.val === hidden.value);
    if (initItem) input.value = initItem.textContent;
    function filter(q) { const nq = norm(q); items.forEach(li => { li.hidden = nq !== '' && !norm(li.textContent).includes(nq); }); }
    input.addEventListener('focus', () => { filter(input.value); list.hidden = false; });
    input.addEventListener('input', () => { filter(input.value); list.hidden = false; });
    input.addEventListener('blur',  () => { setTimeout(() => { list.hidden = true; const cur = items.find(li => li.dataset.val === hidden.value); input.value = cur ? cur.textContent : ''; }, 150); });
    items.forEach(li => { li.addEventListener('mousedown', e => { e.preventDefault(); hidden.value = li.dataset.val; input.value = li.textContent; list.hidden = true; }); });
})();

// Dropdown partagé — lettrage individuel par ligne
(function () {
    const list = document.getElementById('row-cat-list');
    if (!list) return;
    const items = Array.from(list.querySelectorAll('li'));
    const norm = s => s.toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '');
    let activeInput = null, activeHidden = null, activeForm = null;

    function filter(q) { const nq = norm(q); items.forEach(li => { li.hidden = nq !== '' && !norm(li.textContent).includes(nq); }); }
    function position(input) {
        const r = input.getBoundingClientRect();
        list.style.top    = (r.bottom + window.scrollY + 2) + 'px';
        list.style.left   = r.left + 'px';
        list.style.width  = Math.max(r.width, 260) + 'px';
    }

    document.querySelectorAll('.row-cat-input').forEach(input => {
        const form   = input.closest('form');
        const hidden = form.querySelector('.row-cat-val');
        input.addEventListener('focus', () => {
            activeInput = input; activeHidden = hidden; activeForm = form;
            filter(''); list.hidden = false; position(input);
        });
        input.addEventListener('input', () => { filter(input.value); list.hidden = false; position(input); });
        input.addEventListener('blur',  () => {
            setTimeout(() => {
                if (!list.hidden) {
                    list.hidden = true;
                    const cur = items.find(li => li.dataset.val === (activeHidden?.value ?? ''));
                    if (activeInput) activeInput.value = cur && cur.dataset.val !== '' ? cur.textContent : '';
                }
            }, 150);
        });
    });

    items.forEach(li => {
        li.addEventListener('mousedown', e => {
            e.preventDefault();
            if (!activeHidden || !activeInput || !activeForm) return;
            activeHidden.value = li.dataset.val;
            activeInput.value  = li.dataset.val !== '' ? li.textContent : '';
            list.hidden = true;
            activeForm.submit();
        });
    });

    // Fermer si clic en dehors
    document.addEventListener('mousedown', e => {
        if (!list.hidden && !list.contains(e.target) && e.target !== activeInput) list.hidden = true;
    });
    // Repositionner sur scroll
    window.addEventListener('scroll', () => { if (!list.hidden && activeInput) position(activeInput); }, { passive: true });
})();
</script>
<?php endif; ?>
