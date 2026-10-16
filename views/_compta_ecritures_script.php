<?php
// Comportements de la liste des écritures : lettrage en ligne, barre groupée,
// panneau de ventilation analytique, en-tête collant. Sorti de
// views/compta_ecritures.php, qui mêlait 430 lignes de script à son balisage.
//
// Le script reste une balise <script nonce> dans la page — il lit des éléments
// rendus juste au-dessus et n'a pas de sens ailleurs. C'est sa PLACE dans le
// fichier qui changeait, pas sa nature.
?>
<script nonce="<?= e(csp_nonce()) ?>">
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

    // (Le crayon de la colonne Catégorie est pris en charge par l'éditeur
    //  partagé, plus bas.)



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


// Lettrage individuel : UN éditeur pour toute la page, qui se déplace
//
// Le formulaire, son jeton, l'identifiant de l'écriture et le champ de saisie
// vivaient dans CHAQUE ligne — un cinquième du poids de la page pour un
// éditeur dont un seul sert à la fois. Ils sont désormais uniques
// (_compta_ecritures_panneaux.php) et viennent se poser dans la cellule
// ouverte. Le DÉPLACER plutôt que le recréer conserve ses écouteurs, comme
// pour le formulaire d'étiquette de ?p=structures.
//
// La cellule, elle, ne garde que ce qu'elle montre, et dit en data- ce que
// l'éditeur doit savoir : quelle écriture, quelle catégorie courante.
(function () {
    const list = document.getElementById('row-cat-list');
    const form = document.getElementById('ecr-cat-form');
    if (!list || !form) return;
    const inp      = document.getElementById('ecr-cat-inp');
    const input    = inp.querySelector('.row-cat-input');
    const hidden   = form.querySelector('.row-cat-val');
    const idField  = document.getElementById('ecr-cat-id');
    const prefixEl = inp.querySelector('.cat-prefix');

    const items    = Array.from(list.querySelectorAll('li:not(.cat-search-group):not(.cat-search-sens)'));
    const groups   = Array.from(list.querySelectorAll('.cat-search-group'));
    const sensHdrs = Array.from(list.querySelectorAll('.cat-search-sens'));
    const tplDisp  = document.getElementById('tpl-cat-disp');
    let cellule = null;   // la cellule en cours d'édition, s'il y en a une

    function filterGroups() {
        groups.forEach(g => { let s = g.nextElementSibling, v = false; while (s && !s.classList.contains('cat-search-group') && !s.classList.contains('cat-search-sens')) { if (!s.hidden) v = true; s = s.nextElementSibling; } g.hidden = !v; });
        sensHdrs.forEach(h => { let s = h.nextElementSibling, v = false; while (s && !s.classList.contains('cat-search-sens')) { if (!s.hidden) v = true; s = s.nextElementSibling; } h.hidden = !v; });
    }
    function filter(q) { const nq = lassoNorm(q); items.forEach(li => { li.hidden = nq !== '' && !lassoNorm(li.textContent).includes(nq); }); filterGroups(); }
    function position() {
        const r = input.getBoundingClientRect();
        list.style.top   = (r.bottom + window.scrollY + 2) + 'px';
        list.style.left  = r.left + 'px';
        list.style.width = Math.max(r.width, 260) + 'px';
    }

    // Ce que la cellule montre au repos : sa ligne de lecture, ou le bouton
    // d'une écriture jamais lettrée. L'un des deux, jamais les deux.
    const repos = c => c.querySelector('.row-field-disp') || c.querySelector('.row-cat-vide');

    function ouvrir(cell) {
        if (cellule === cell) return;
        fermer();
        cellule = cell;
        idField.value   = cell.dataset.ecrId || '';
        hidden.value    = cell.dataset.catVal || '';
        prefixEl.textContent = cell.dataset.catPrefix || '';
        const r = repos(cell);
        if (r) { r.hidden = true; }
        cell.appendChild(inp);
        inp.hidden = false;
        input.value = '';          // vidé pour la recherche
        input.focus();
        filter(''); list.hidden = false; position();
    }

    // L'éditeur rentre chez lui et la cellule retrouve son affichage.
    function fermer() {
        if (!cellule) return;
        const c = cellule;
        cellule = null;
        inp.hidden = true;
        form.appendChild(inp);
        const r = repos(c);
        if (r) { r.hidden = false; }
    }

    document.addEventListener('click', e => {
        const btn = e.target.closest('.row-edit-btn:not(.axe-edit-btn):not(.axe-add-btn), .row-cat-vide');
        const cell = btn && btn.closest('.cat-cell');
        if (cell) { ouvrir(cell); }
    });

    input.addEventListener('input', () => { filter(input.value); list.hidden = false; position(); });
    input.addEventListener('blur', () => {
        setTimeout(() => { list.hidden = true; fermer(); }, 150);
    });

    // Les valeurs de l'entonnoir « Catégorie » en cours : une ligne qui cesse
    // d'y répondre n'a plus sa place dans la liste affichée, et la laisser là
    // ferait mentir le filtre. Seuls les deux états — « à lettrer », « ne pas
    // lettrer » — sont traités : un filtre sur une catégorie précise porte
    // aussi sur ses descendants, que le navigateur ne connaît pas, et la ligne
    // y reste alors avec sa nouvelle valeur, ce qui ne ment sur rien.
    const CAT_FILTRE = <?= json_encode(array_values((array) $categorieFilter), JSON_UNESCAPED_UNICODE) ?>;

    // Réécrit la cellule après un enregistrement : le libellé choisi, l'état de
    // la ligne, et son retrait si elle sort du filtre.
    function majCellule(cell, li) {
        const val     = li.dataset.val;
        const prefixe = val !== '' ? (li.dataset.prefix || '') : '';
        const feuille = val !== '' ? (li.dataset.leaf || '') : '';
        cell.dataset.catVal    = val;
        cell.dataset.catPrefix = prefixe;

        let disp = cell.querySelector('.row-field-disp');
        let vide = cell.querySelector('.row-cat-vide');
        if (val === '') {
            // Délettrée : retour au bouton d'une ligne jamais lettrée.
            if (disp) { disp.remove(); }
            if (!vide) {
                vide = document.createElement('button');
                vide.type = 'button';
                vide.className = 'row-cat-vide';
                vide.textContent = '— à lettrer —';
                cell.appendChild(vide);
            }
        } else {
            if (vide) { vide.remove(); }
            if (!disp) {
                disp = tplDisp.content.firstElementChild.cloneNode(true);
                cell.appendChild(disp);
            }
            // ⚠️ Le <span> du préfixe n'existe pas sur une ligne rendue SANS
            // préfixe (catégorie racine) : le serveur ne l'écrit que s'il a
            // quelque chose à y mettre. L'écrire tout droit lançait une erreur
            // sur null — et la cellule gardait alors l'ancien libellé alors que
            // la base, elle, avait bien changé. On le crée au besoin, dans
            // l'ordre du gabarit (préfixe avant feuille).
            let pfxEl = disp.querySelector('.row-field-prefix');
            if (!pfxEl) {
                pfxEl = document.createElement('span');
                pfxEl.className = 'row-field-prefix';
                disp.querySelector('.row-field-txt').prepend(pfxEl);
            }
            pfxEl.textContent = prefixe;
            disp.querySelector('.row-field-leaf').textContent = feuille;
        }
        // L'état de la ligne : il porte sa couleur de fond (non lettrée,
        // « ne pas lettrer »).
        const tr = cell.closest('tr');
        tr.classList.toggle('non-lettre', val === '');
        tr.classList.toggle('ecr-ignore', val === 'ignore');
        const etat = val === '' ? 'a_lettrer' : (val === 'ignore' ? 'ignore' : 'categorie');
        const filtreEtat = CAT_FILTRE.filter(v => v === 'a_lettrer' || v === 'ignore');
        if (filtreEtat.length && !filtreEtat.includes(etat)) {
            tr.remove();
        }
    }

    async function enregistrerCategorie(cell, li) {
        const fd = new FormData(form);
        fd.append('retour', 'json');
        try {
            const data = await fetch('?p=compta_ecritures', { method: 'POST', body: fd }).then(r => r.json());
            if (!data || !data.ok) { form.submit(); return; }
        } catch (_) {
            // Repli : l'envoi classique du formulaire, qui rechargera la page et
            // montrera l'état réel plutôt que de laisser une cellule réécrite à
            // l'écran et rien en base (docs/UI.md § 4).
            form.submit();
            return;
        }
        majCellule(cell, li);
    }

    items.forEach(li => {
        li.addEventListener('mousedown', e => {
            e.preventDefault();
            if (!cellule) return;
            // La cellule est captée MAINTENANT : le blur qui suit la libère
            // avant que la requête ne revienne.
            const cell = cellule;
            hidden.value = li.dataset.val;
            list.hidden = true;
            fermer();
            enregistrerCategorie(cell, li);
        });
    });

    document.addEventListener('mousedown', e => {
        if (!list.hidden && !list.contains(e.target) && e.target !== input) list.hidden = true;
    });
    window.addEventListener('scroll', () => { if (!list.hidden && cellule) position(); }, { passive: true });
})();

// Colonne axe analytique : select inline (0 axe), inline edit (1 axe), panneau multi-axe (≥2)
(function () {
    const panel     = document.getElementById('axe-panel');
    if (!panel) return;
    const rowsEl    = document.getElementById('axe-panel-rows');
    const sumEl     = document.getElementById('axe-panel-sum');
    const refEl     = document.getElementById('axe-panel-ref');
    const resteEl   = document.getElementById('axe-panel-reste');
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

    // Les sélecteurs d'axe des lignes non ventilées naissent vides et se
    // remplissent ici : la liste des axes est déjà dans ce script (AXES), la
    // recopier dans chaque ligne doublait le poids de la colonne pour rien.
    document.querySelectorAll('.axe-inline-sel[data-vide]').forEach(vide => {
        vide.replaceWith(buildInlineSel(null));
    });

    function buildPlusBtn(cell) {
        const btn = document.createElement('button');
        btn.type = 'button'; btn.className = 'row-edit-btn axe-add-btn';
        btn.title = 'Ventilation multi-axe';
        btn.setAttribute('aria-label', 'Ventiler sur plusieurs axes');
        btn.innerHTML = PLUS_ICON;
        btn.addEventListener('click', () => openPanel(cell, true));
        return btn;
    }

    function buildPencilBtn(cell, mode) {
        const btn = document.createElement('button');
        btn.type = 'button'; btn.className = 'row-edit-btn axe-edit-btn';
        btn.title = mode === 'inline' ? "Modifier l'axe" : 'Modifier la ventilation';
        btn.setAttribute('aria-label', mode === 'inline'
            ? "Modifier l'axe analytique" : 'Modifier la ventilation analytique');
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
            const data = await fetch('?p=compta_ventilation_enregistrer', { method: 'POST', body: fd }).then(r => r.json());
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
        const ref = Math.abs(parseFloat(currentCell?.dataset.ecrMontant || 0));
        // Le reste porte seul la couleur : la somme la portait aussi, et deux
        // chiffres teintés pour une même information en disaient une de trop.
        const reste = Math.round((ref - Math.abs(total)) * 100) / 100;
        if (Math.abs(reste) < 0.005) {
            resteEl.textContent = '· tout est réparti';
            resteEl.className = 'axe-panel-reste est-complet';
        } else if (reste > 0) {
            resteEl.textContent = '· reste ' + reste.toFixed(2) + ' à répartir';
            resteEl.className = 'axe-panel-reste est-reste';
        } else {
            resteEl.textContent = '· ' + Math.abs(reste).toFixed(2) + ' de trop';
            resteEl.className = 'axe-panel-reste est-trop';
        }
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
            const data = await fetch('?p=compta_ventilation_enregistrer', { method: 'POST', body: fd }).then(r => r.json());
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
