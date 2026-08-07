// Fonctions JS partagées entre plusieurs pages — chargé une seule fois depuis
// views/layout.php. Garder ce fichier minimal : une fonction ici doit être
// utilisée par au moins deux vues, sinon elle reste locale à sa vue.

// Bandeau « X ligne(s) modifiée(s) — Annuler » affiché après une modification
// groupée (voir bulk_undo_memoriser()/bulk_undo_appliquer() dans lib/helpers.php,
// inséré par views/_bulk_undo_flash.php). Le bandeau se masque après 10 s, mais
// Ctrl-Z/Cmd+Z reste actif au-delà (jusqu'à l'expiration côté serveur, 5 min,
// ou tant que la page n'a pas été rechargée) : l'utilisateur ne devrait pas
// perdre la possibilité d'annuler juste parce qu'il a mis quelques secondes à
// réagir. Ce script est chargé dans <head>, avant que le bandeau n'existe
// dans le DOM : on attend le chargement de la page avant de le chercher.
window.addEventListener('DOMContentLoaded', () => {
    const flash = document.getElementById('bulk-undo-flash');
    if (!flash) return;
    setTimeout(() => { flash.hidden = true; }, 10000);
    document.addEventListener('keydown', e => {
        if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'z') {
            e.preventDefault();
            flash.querySelector('form').requestSubmit();
        }
    });
});

// Filtres secondaires (.filters-more, ex. ?p=lieux, ?p=structures) : la
// réserve d'espace sous la ligne de filtres (margin-bottom, voir
// .filters:has(.filters-more[open]) dans assets/app.css) est une valeur fixe
// pensée pour un panneau tenant sur une ligne — insuffisante depuis que
// certaines listes (lieux, structures) ont assez de champs pour que le
// panneau (position:absolute, largeur plafonnée) s'étale sur plusieurs
// lignes, ce qui le faisait chevaucher le tableau. On mesure la hauteur
// réelle du panneau ouvert (variable aussi selon la largeur d'écran) pour
// ajuster la réserve en conséquence.
window.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('.filters-more').forEach(details => {
        const body = details.querySelector('.filters-more-body');
        const filtres = details.closest('.filters');
        if (!body || !filtres) return;
        const ajuster = () => {
            if (!details.open) { filtres.style.removeProperty('margin-bottom'); return; }
            filtres.style.marginBottom = (body.offsetHeight + 24) + 'px';
        };
        details.addEventListener('toggle', ajuster);
        window.addEventListener('resize', () => { if (details.open) ajuster(); });
        if (details.open) ajuster();
    });
});

// Filtre de colonne (.col-filter, ex. "Paiement" sur ?p=fiches) : <details>
// natif, comme .filters-more ci-dessus, mais celui-ci ne se ferme pas tout
// seul au clic dehors (comportement natif de <details> — reste ouvert tant
// qu'on ne reclique pas sur son <summary>). Un seul écouteur global : ferme
// tout .col-filter ouvert dont le clic n'a pas eu lieu à l'intérieur.
document.addEventListener('click', e => {
    document.querySelectorAll('.col-filter[open]').forEach(details => {
        if (!details.contains(e.target)) { details.open = false; }
    });
});

// Cadres lecture/édition (?p=evenement : Informations/Organisation/
// Localisation ; ?p=structure : Catégorie/Type/Connu via/Coordonnées/Site
// web/Remarques…) : un seul script générique, partagé entre ces vues — le
// bouton crayon révèle .card-edit et masque .card-disp, jamais l'inverse
// tant qu'on ne recharge pas la page (« Annuler » est un simple lien vers la
// page elle-même). Le crayon est remplacé par les boutons enregistrer/
// annuler, au même endroit — tous trois vivent dans le même conteneur
// .head-actions, juste à côté du crayon (voir aussi .card-actions-overlay
// dans app.css pour les cadres sans ligne d'en-tête propre).
window.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('.card-edit-btn').forEach(btn => {
        btn.addEventListener('click', () => {
            const card = btn.closest('.card-editable');
            card.querySelector('.card-disp').hidden = true;
            card.querySelector('.card-edit').hidden = false;
            const actions = btn.closest('.head-actions');
            btn.hidden = true;
            actions.querySelector('.card-save-btn').hidden = false;
            actions.querySelector('.card-cancel-btn').hidden = false;
        });
    });
});

// Recherche texte adossée au serveur (?q=), pour les listes paginées : filtrer
// seulement les lignes déjà chargées dans le DOM laisserait de côté tout ce
// qui est sur une autre page. Débounce après la dernière frappe puis recharge
// avec ?q=... en repartant en page 1 ; Entrée déclenche immédiatement. Les
// autres paramètres déjà dans l'URL (filtres, taille) sont préservés tels quels.
function lassoRechercheServeur(input) {
    if (!input) return;
    let timer = null;
    const aller = () => {
        clearTimeout(timer);
        const params = new URLSearchParams(location.search);
        const q = input.value.trim();
        if (q === '') { params.delete('q'); } else { params.set('q', q); }
        params.set('page', '1');
        location.href = '?' + params.toString();
    };
    input.addEventListener('input', () => { clearTimeout(timer); timer = setTimeout(aller, 400); });
    input.addEventListener('keydown', e => { if (e.key === 'Enter') { e.preventDefault(); aller(); } });
}

// Normalise une chaîne pour une recherche insensible à la casse et aux accents.
function lassoNorm(s) {
    return s.toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '');
}

// Liste "petite" (≤ PAGINATION_SEUIL_CLIENT côté serveur, voir lib/helpers.php) :
// pagination + recherche 100% en JS, sans aller-retour serveur — toutes les
// lignes sont déjà dans le DOM. Au-delà du seuil, voir lassoRechercheServeur()
// (recherche) + views/_pagination.php (pagination, LIMIT SQL côté serveur) ;
// en dessous, views/_pagination_client.php fournit le rendu ci-dessous.
// config : { tableSelector, searchInputSelector, searchCountSelector,
// separatorSelector } — tous optionnels sauf tableSelector.
function lassoListeClient(config) {
    const table = document.querySelector(config.tableSelector);
    const pag = document.querySelector('[data-pg-client]');
    if (!table) return;
    const allRows = Array.from(table.querySelectorAll('tbody tr'));
    const rows = config.separatorSelector
        ? allRows.filter(r => !r.matches(config.separatorSelector))
        : allRows;
    const search = config.searchInputSelector ? document.querySelector(config.searchInputSelector) : null;
    const searchCount = config.searchCountSelector ? document.querySelector(config.searchCountSelector) : null;

    let page = 1;
    let taille = (pag && parseInt(pag.dataset.pgTailleDefaut, 10)) || 100;
    let matched = rows;

    function syncSeparators() {
        if (!config.separatorSelector) return;
        let sep = null, sepVisible = false;
        allRows.forEach(r => {
            if (r.matches(config.separatorSelector)) {
                if (sep) sep.style.display = sepVisible ? '' : 'none';
                sep = r; sepVisible = false;
            } else if (r.style.display !== 'none') {
                sepVisible = true;
            }
        });
        if (sep) sep.style.display = sepVisible ? '' : 'none';
    }

    // Mêmes règles que pagination_pages_affichees() (lib/helpers.php, pendant
    // serveur de views/_pagination.php) : 1re/dernière page toujours visibles,
    // page courante ± 1, '…' (chaîne, pas de sens numérique) pour les trous.
    function pagesAffichees(pageCourante, nbPages) {
        if (nbPages <= 7) {
            return Array.from({ length: nbPages }, (_, i) => i + 1);
        }
        const brut = [...new Set([1, 2, pageCourante - 1, pageCourante, pageCourante + 1, nbPages - 1, nbPages])]
            .filter(p => p >= 1 && p <= nbPages)
            .sort((a, b) => a - b);
        const out = [];
        let prec = null;
        brut.forEach(p => {
            if (prec !== null && p - prec > 1) out.push('…');
            out.push(p);
            prec = p;
        });
        return out;
    }

    function render() {
        const total = matched.length;
        const nbPages = Math.max(1, Math.ceil(total / taille));
        page = Math.min(Math.max(page, 1), nbPages);
        const debut = (page - 1) * taille;
        const fin = Math.min(debut + taille, total);
        rows.forEach(r => { r.style.display = 'none'; });
        matched.slice(debut, fin).forEach(r => { r.style.display = ''; });
        syncSeparators();

        if (!pag) return;
        const info = pag.querySelector('[data-pg-info]');
        const numbers = pag.querySelector('[data-pg-numbers]');
        const nav = pag.querySelector('[data-pg-nav]');
        if (total > taille) {
            info.textContent = (total === 0 ? 0 : debut + 1) + '–' + fin + ' sur ' + total;
            numbers.innerHTML = '';
            pagesAffichees(page, nbPages).forEach(p => {
                if (p === '…') {
                    const span = document.createElement('span');
                    span.className = 'pagination-ellipsis';
                    span.textContent = '…';
                    numbers.appendChild(span);
                    return;
                }
                const el = document.createElement(p === page ? 'span' : 'button');
                el.className = 'pagination-page' + (p === page ? ' on' : '');
                el.textContent = p;
                if (p === page) {
                    el.setAttribute('aria-current', 'page');
                } else {
                    el.type = 'button';
                    el.addEventListener('click', () => { page = p; render(); });
                }
                numbers.appendChild(el);
            });
            nav.hidden = false;
            nav.querySelector('[data-pg-prev]').disabled = page <= 1;
            nav.querySelector('[data-pg-next]').disabled = page >= nbPages;
        } else {
            info.textContent = total + (total > 1 ? ' résultats' : ' résultat');
            nav.hidden = true;
        }
    }

    function filtrer() {
        const q = search ? lassoNorm(search.value.trim()) : '';
        matched = q === '' ? rows : rows.filter(r => lassoNorm(r.textContent).includes(q));
        page = 1;
        if (searchCount) {
            searchCount.textContent = q === '' ? '' : matched.length + ' / ' + rows.length + ' affiché(e)s';
        }
        render();
    }

    if (search) search.addEventListener('input', filtrer);
    if (pag) {
        pag.querySelector('[data-pg-taille]').addEventListener('change', e => {
            taille = parseInt(e.target.value, 10);
            page = 1;
            render();
        });
        pag.querySelector('[data-pg-prev]').addEventListener('click', () => { page--; render(); });
        pag.querySelector('[data-pg-next]').addEventListener('click', () => { page++; render(); });
    }
    filtrer(); // applique tout de suite une valeur de recherche déjà présente (lien profond ?q=...)
}

// Dropdown « catégorie/axe cherchable » (texte + valeur cachée + liste
// filtrée au clavier). Couvre les variantes utilisées dans compta_ecritures.php
// (formulaire manuel, barre de modification groupée), compta_regles.php et
// evenement_form.php — chacune avec son propre jeu d'options ci-dessous.
// Le dropdown délégué à ligne unique de compta_ecritures.php (#row-cat-list,
// liste partagée par plusieurs inputs avec positionnement + soumission auto)
// reste volontairement à part : sa forme diffère trop pour un partage sûr.
function lassoInitCatSearch(wrap, opts = {}) {
    const {
        groupsFilter = false,       // masque aussi les en-têtes .cat-search-group/.cat-search-sens sans résultat
        hydrateInitial = false,     // pré-remplit le texte depuis la valeur cachée au chargement (si le HTML ne le fait pas déjà côté serveur)
        showPlaceholderText = false, // au blur/sélection, afficher le texte même pour une option de valeur "" (ex. « — Retirer — »)
        clearHiddenOnInput = false,  // en tapant, vide la valeur cachée (oblige à resélectionner avant de soumettre)
        onSelect = null,             // rappel optionnel(li) après une sélection
    } = opts;

    const input  = wrap.querySelector('.cat-search-input');
    const hidden = wrap.querySelector('.cat-search-val');
    const list   = wrap.querySelector('.cat-search-list');
    // Requêtées à chaque usage (pas une fois pour toutes en constantes) :
    // certains appelants peuplent la liste après coup (ex. options chargées
    // par fetch au premier focus, evenement_form.php) — figer items/groups au
    // moment de l'init les rendrait invisibles au filtre et au clic tant que
    // lassoInitCatSearch() n'est pas rappelée, ce qui provoquait justement le
    // bug « il faut cliquer 2-3 fois » sur les sélecteurs de lieu.
    function items() {
        return Array.from(list.querySelectorAll(groupsFilter ? 'li:not(.cat-search-group):not(.cat-search-sens)' : 'li'));
    }
    function groups() { return groupsFilter ? Array.from(list.querySelectorAll('.cat-search-group')) : []; }
    function sensHdrs() { return groupsFilter ? Array.from(list.querySelectorAll('.cat-search-sens')) : []; }

    if (hydrateInitial) {
        const initItem = items().find(li => li.dataset.val === hidden.value);
        if (initItem) input.value = initItem.textContent;
    }

    function filterGroups() {
        groups().forEach(g => { let s = g.nextElementSibling, v = false; while (s && !s.classList.contains('cat-search-group') && !s.classList.contains('cat-search-sens')) { if (!s.hidden) v = true; s = s.nextElementSibling; } g.hidden = !v; });
        sensHdrs().forEach(h => { let s = h.nextElementSibling, v = false; while (s && !s.classList.contains('cat-search-sens')) { if (!s.hidden) v = true; s = s.nextElementSibling; } h.hidden = !v; });
    }
    function filter(q) {
        const nq = lassoNorm(q);
        items().forEach(li => { li.hidden = nq !== '' && !lassoNorm(li.textContent).includes(nq); });
        if (groupsFilter) filterGroups();
    }
    function textFor(li) {
        return (showPlaceholderText || li.dataset.val !== '') ? li.textContent : '';
    }

    input.addEventListener('focus', () => { filter(input.value); list.hidden = false; });
    input.addEventListener('input', () => {
        filter(input.value); list.hidden = false;
        if (clearHiddenOnInput) hidden.value = '';
    });
    input.addEventListener('blur', () => {
        setTimeout(() => {
            list.hidden = true;
            const cur = items().find(li => li.dataset.val === hidden.value);
            input.value = cur ? textFor(cur) : '';
        }, 150);
    });
    // Délégation sur la liste plutôt qu'un écouteur par <li> : fonctionne
    // aussi pour des options ajoutées après l'initialisation, sans dupliquer
    // d'écouteurs si lassoInitCatSearch() était rappelée.
    list.addEventListener('mousedown', e => {
        const li = e.target.closest('li');
        if (!li || !list.contains(li)) return;
        e.preventDefault();
        hidden.value = li.dataset.val;
        input.value = textFor(li);
        list.hidden = true;
        if (onSelect) onSelect(li);
    });
}

// Marquage rapide (flag) devant le nom d'une structure/d'un lieu — bouton à 3
// états cyclés au clic (aucun → étoile → cœur → aucun), voir flag_toggle_html()
// (lib/helpers.php) et route_lieu_flag()/route_structure_flag(). Un seul jeton
// CSRF récupéré depuis n'importe quel formulaire protégé déjà présent sur la
// page (pas la peine de le répéter sur chaque bouton — potentiellement des
// centaines de lignes sur ?p=lieux/?p=structures). Idempotent
// (data-flag-bound) : peut être rappelé sans dupliquer les écouteurs.
const LASSO_FLAG_ICONES = {
    star: '<svg class="ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M11.525 2.295a.53.53 0 0 1 .95 0l2.31 4.679a2.123 2.123 0 0 0 1.595 1.16l5.166.756a.53.53 0 0 1 .294.904l-3.736 3.638a2.123 2.123 0 0 0-.611 1.878l.882 5.14a.53.53 0 0 1-.771.56l-4.618-2.428a2.122 2.122 0 0 0-1.973 0L6.396 21.01a.53.53 0 0 1-.77-.56l.881-5.139a2.122 2.122 0 0 0-.611-1.879L2.16 9.795a.53.53 0 0 1 .294-.906l5.165-.755a2.122 2.122 0 0 0 1.597-1.16z"/></svg>',
    heart: '<svg class="ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M2 9.5a5.5 5.5 0 0 1 9.591-3.676.56.56 0 0 0 .818 0A5.49 5.49 0 0 1 22 9.5c0 2.29-1.5 4-3 5.5l-5.492 5.313a2 2 0 0 1-3 .019L5 15c-1.5-1.5-3-3.2-3-5.5"/></svg>',
};
const LASSO_FLAG_LABELS = {
    star: 'Marqué (étoile) — cliquer pour retirer le marquage',
    heart: 'Marqué (cœur) — cliquer pour retirer le marquage',
    '': 'Non marqué — cliquer pour marquer',
};
function lassoInitFlagToggle() {
    const csrfInput = document.querySelector('input[name="csrf"]');
    document.querySelectorAll('.flag-toggle').forEach(btn => {
        if (btn.dataset.flagBound) return;
        btn.dataset.flagBound = '1';
        btn.addEventListener('click', async e => {
            e.preventDefault();
            e.stopPropagation();
            if (!csrfInput) return;
            const route = btn.dataset.flagTable === 'lieu' ? 'lieu_flag' : 'structure_flag';
            const fd = new FormData();
            fd.append('csrf', csrfInput.value);
            fd.append('id', btn.dataset.flagId);
            const data = await fetch('?p=' + route, { method: 'POST', body: fd })
                .then(r => r.json()).catch(() => null);
            if (!data || !data.ok) return;
            const flag = data.flag || '';
            btn.dataset.flagValeur = flag;
            btn.className = 'flag-toggle flag-' + (flag || 'aucun');
            btn.innerHTML = LASSO_FLAG_ICONES[flag === 'heart' ? 'heart' : 'star'];
            const label = LASSO_FLAG_LABELS[flag] ?? LASSO_FLAG_LABELS[''];
            btn.title = label;
            btn.setAttribute('aria-label', label);
        });
    });
}

// Statut d'une structure (fiche, carte « Statut ») — sélecteur segmenté
// (même style visuel que le champ Type de « Informations générales », voir
// icon_picker()/.seg-picker), mais cliqué en AJAX au lieu d'être soumis avec
// le reste du formulaire (voir structure_statut_toggle_html(), lib/helpers.php,
// et route_structure_statut()).
function lassoInitStatutToggle() {
    const csrfInput = document.querySelector('input[name="csrf"]');
    document.querySelectorAll('.statut-toggle').forEach(picker => {
        if (picker.dataset.statutBound) return;
        picker.dataset.statutBound = '1';
        const boutons = picker.querySelectorAll('.seg-btn');
        boutons.forEach(btn => {
            btn.addEventListener('click', async () => {
                if (!csrfInput || btn.classList.contains('on')) return;
                const fd = new FormData();
                fd.append('csrf', csrfInput.value);
                fd.append('id', picker.dataset.statutId);
                fd.append('etat', btn.dataset.statutValeur);
                const data = await fetch('?p=structure_statut', { method: 'POST', body: fd })
                    .then(r => r.json()).catch(() => null);
                if (!data || !data.ok) return;
                boutons.forEach(b => {
                    const on = b.dataset.statutValeur === data.etat;
                    b.classList.toggle('on', on);
                    b.setAttribute('aria-checked', on ? 'true' : 'false');
                });
            });
        });
    });
}

// Carte Leaflet des vues carte (lieux/structures/événements) — factorisé,
// même init pour les 3 pages (voir views/_lieux_carte.php,
// _structures_carte.php, _evenements_carte.php). Mémorise position + zoom
// dans sessionStorage (storageKey distinct par page) pour les restaurer si
// l'utilisateur revient en arrière depuis la fiche d'un marqueur (clic sur un
// lieu/structure/événement dans une popup, puis bouton Précédent) — sinon la
// carte se recentrait systématiquement sur l'ensemble des points, perdant le
// zoom/la position choisis manuellement. sessionStorage (pas localStorage) :
// ne doit pas persister au-delà de l'onglet.
function lassoInitCarteLieux(mapId, points, storageKey) {
    const map = L.map(mapId);
    L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', {
        maxZoom: 19,
        attribution: '&copy; <a href="https://www.openstreetmap.org/copyright" target="_blank" rel="noopener">OpenStreetMap</a>',
    }).addTo(map);

    // Un point = une ville (déjà groupée par carte_points_grouper()), qui peut
    // contenir plusieurs fiches. Légèrement plus grand dès que le point en
    // regroupe plusieurs, pour repérer les villes à forte densité sans avoir
    // à ouvrir chaque popup — même couleur partout, pas de nombre affiché.
    points.forEach(p => {
        const n = p.count || 1;
        const taille = n > 1 ? Math.min(16 + n, 22) : 14;
        const icon = L.divIcon({
            className: 'carte-pin',
            iconSize: [taille, taille],
            iconAnchor: [taille / 2, taille / 2],
        });
        L.marker([p.lat, p.lon], { icon }).addTo(map).bindPopup(p.popup);
    });

    let vueRestauree = false;
    try {
        const sauvee = JSON.parse(sessionStorage.getItem(storageKey) || 'null');
        if (sauvee && typeof sauvee.lat === 'number' && typeof sauvee.lng === 'number' && typeof sauvee.zoom === 'number') {
            map.setView([sauvee.lat, sauvee.lng], sauvee.zoom, { animate: false });
            vueRestauree = true;
        }
    } catch (e) { /* sessionStorage indisponible (navigation privée…) : tant pis, pas de restauration */ }

    if (!vueRestauree) {
        if (points.length) {
            map.fitBounds(points.map(p => [p.lat, p.lon]), { padding: [30, 30], maxZoom: 12, animate: false });
        } else {
            map.setView([46.8, 2.5], 5, { animate: false });
        }
    }

    map.on('moveend', () => {
        try {
            const c = map.getCenter();
            sessionStorage.setItem(storageKey, JSON.stringify({ lat: c.lat, lng: c.lng, zoom: map.getZoom() }));
        } catch (e) {}
    });

    return map;
}

// Boutons « Nouveau »/« Annuler » qui affichent/masquent une ligne d'ajout
// (id ciblé par data-show/data-hide). Couvre compta_plan.php, spectacles.php
// et taux_horaires.php. data-focus (optionnel, sur le bouton data-show) donne
// le sélecteur du champ à focaliser à l'ouverture ; défaut : premier champ
// texte non caché. Délégué sur document : ce script est chargé dans <head>,
// avant que ces boutons n'existent dans le DOM.
document.addEventListener('click', e => {
    const show = e.target.closest('[data-show]');
    if (show) {
        const t = document.getElementById(show.dataset.show);
        if (!t) return;
        t.hidden = false;
        t.querySelector(show.dataset.focus || 'input:not([type=hidden])')?.focus();
        return;
    }
    const hide = e.target.closest('[data-hide]');
    if (hide) {
        const t = document.getElementById(hide.dataset.hide);
        if (t) t.hidden = true;
    }
    // Texte tronqué (> 200 caractères, ex. Remarques d'une fiche structure) :
    // « voir tout » révèle le texte complet, remplace le tronqué. Générique
    // (réutilisable pour d'autres champs longs) : cherche les deux spans
    // frères dans le même parent, pas d'id à câbler par champ.
    const voirTout = e.target.closest('.voir-tout-btn');
    if (voirTout) {
        const cell = voirTout.parentElement;
        cell.querySelector('.notes-tronquees').hidden = true;
        cell.querySelector('.notes-completes').hidden = false;
        voirTout.hidden = true;
    }
});

// Arbre hiérarchique avec glisser-déposer pour réordonner/reparenter (rangées
// .plan-row avec data-id/data-parent/data-depth) — factorisé depuis
// spectacles.php et compta_plan.php (mêmes classes CSS .plan-*/.dnd-on, voir
// app.css). Glisser une ligne calcule (parent, profondeur, ordre des frères)
// et soumet #reorder-form (id/parent_id/order — le serveur revalide toujours
// la cohérence, ex. cycles interdits ou profondeur imposée). Gère aussi le
// renommage en ligne (crayon → focus ; Entrée/blur soumet ; Échap annule) et
// le passage lecture seule → contrôles actifs (.dnd-on, une fois le JS prêt).
//
// opts.containerSelector : élément(s) recevant .dnd-on quand le JS est actif.
// opts.rowsSelector       : sélecteur des lignes .plan-row (généralement '.plan-row').
// opts.scrollKey          : clé sessionStorage pour restaurer le défilement.
// opts.formAction         : action des formulaires de la page (déclenche saveScroll à la soumission).
// opts.groupAttr          : optionnel — nom d'attribut data-<groupAttr> (ex. 'sens') quand
//                           plusieurs arbres indépendants coexistent sur la page (ex. plan comptable
//                           produits/charges) ; une ligne ne peut être déposée que parmi celles du
//                           même groupe. Omis si un seul arbre (spectacles, catégories de structures).
function lassoPlanArbre(opts) {
    const { containerSelector, rowsSelector, scrollKey, formAction, groupAttr = null } = opts;

    if ('scrollRestoration' in history) history.scrollRestoration = 'manual';
    const sc = sessionStorage.getItem(scrollKey);
    if (sc !== null) { sessionStorage.removeItem(scrollKey); window.scrollTo(0, parseInt(sc, 10) || 0); }
    const saveScroll = () => sessionStorage.setItem(scrollKey, window.scrollY);
    document.querySelectorAll('form[action="' + formAction + '"]').forEach(f => f.addEventListener('submit', saveScroll));

    document.querySelectorAll(containerSelector).forEach(el => el.classList.add('dnd-on'));

    document.querySelectorAll('.plan-edit-btn').forEach(btn => {
        btn.addEventListener('click', () => {
            const row = btn.closest('.plan-row');
            row.classList.add('editing');
            const inp = row.querySelector('.plan-libelle');
            inp.dataset.orig = inp.value;
            inp.focus(); inp.select();
        });
    });
    document.querySelectorAll('.plan-libelle').forEach(inp => {
        const finir = () => inp.closest('.plan-row').classList.remove('editing');
        inp.addEventListener('change', () => {
            const f = inp.closest('form');
            (f.requestSubmit ? f.requestSubmit() : f.submit());
        });
        inp.addEventListener('blur', finir);
        inp.addEventListener('keydown', e => {
            if (e.key === 'Escape') { inp.value = inp.dataset.orig ?? inp.value; finir(); inp.blur(); }
            else if (e.key === 'Enter') { e.preventDefault(); inp.blur(); }
        });
    });

    const INDENT = 22; // px par niveau
    let dragId = null, dragGroup = null, startX = 0, indic = null;
    const clamp = (v, lo, hi) => Math.max(lo, Math.min(hi, v));

    document.querySelectorAll('.plan-grip').forEach(g => {
        g.addEventListener('dragstart', e => {
            const row = g.closest('.plan-row');
            dragId = row.dataset.id; startX = e.clientX;
            dragGroup = groupAttr ? row.dataset[groupAttr] : null;
            e.dataTransfer.effectAllowed = 'move';
            e.dataTransfer.setData('text/plain', dragId);
        });
    });

    function visibles() {
        const sel = groupAttr ? rowsSelector + '[data-' + groupAttr + '="' + dragGroup + '"]' : rowsSelector;
        const all = [...document.querySelectorAll(sel)]
            .map(r => ({ id: r.dataset.id, parent: r.dataset.parent || '0', depth: +r.dataset.depth, el: r }));
        const byId = {}; all.forEach(i => byId[i.id] = i);
        const estDescendant = id => { let c = byId[id]; while (c) { if (c.id === dragId) return true; c = byId[c.parent]; } return false; };
        return { liste: all.filter(i => i.id !== dragId && !estDescendant(i.id)), byId };
    }

    // Projette (parent, ancre, profondeur) pour un dépôt à la ligne survolée.
    // Si le curseur est dans le tiers supérieur de la première ligne visible,
    // insère avant elle (permet de placer en tête de liste).
    function projeter(e) {
        if (!dragId) return null;
        const over = e.target.closest('.plan-row');
        if (!over) return null;
        if (groupAttr && over.dataset[groupAttr] !== dragGroup) return null;
        const { liste, byId } = visibles();
        const idx = liste.findIndex(i => i.id === over.dataset.id);
        if (idx < 0) return null;

        const rOver = over.getBoundingClientRect();
        const avantPremier = idx === 0 && e.clientY < rOver.top + rOver.height * 0.38;

        let prev, next, depth, parent, anchor;
        if (avantPremier) {
            depth = 0; parent = '0'; anchor = null;
            next = liste[0];
        } else {
            prev = liste[idx]; next = liste[idx + 1];
            const dragDepth = Math.round((e.clientX - startX) / INDENT);
            const maxDepth = prev.depth + 1;
            const minDepth = next ? next.depth : 0;
            depth = clamp(prev.depth + dragDepth, minDepth, maxDepth);
            if (depth === prev.depth + 1) {
                parent = prev.id; anchor = null;
            } else {
                let cur = prev;
                while (cur && cur.depth > depth) cur = byId[cur.parent];
                parent = cur ? cur.parent : '0'; anchor = cur ? cur.id : null;
            }
        }

        const freres = liste.filter(i => i.parent === parent).map(i => i.id);
        let order;
        if (anchor === null) order = [dragId, ...freres];
        else { const k = freres.indexOf(anchor); order = [...freres.slice(0, k + 1), dragId, ...freres.slice(k + 1)]; }
        return { parent: parent === '0' ? '' : parent, order, depth, afterEl: over, avantPremier };
    }

    function showIndic(p) {
        if (!indic) {
            indic = document.createElement('div');
            indic.className = 'plan-indic';
            document.body.appendChild(indic);
        }
        const r = p.afterEl.getBoundingClientRect();
        const off = p.depth * INDENT;
        const y = p.avantPremier ? r.top : r.bottom;
        indic.style.display = 'block';
        indic.style.top = (y - 1) + 'px';
        indic.style.left = (r.left + off) + 'px';
        indic.style.width = Math.max(40, r.right - r.left - off - 12) + 'px';
    }
    function hideIndic() { if (indic) indic.style.display = 'none'; }

    document.addEventListener('dragover', e => {
        const p = projeter(e);
        if (!p) { hideIndic(); return; }
        e.preventDefault();
        showIndic(p);
    });
    document.addEventListener('drop', e => {
        const p = projeter(e);
        hideIndic();
        if (!p) return;
        e.preventDefault();
        const f = document.getElementById('reorder-form');
        f.querySelector('[name=id]').value = dragId;
        f.querySelector('[name=parent_id]').value = p.parent;
        f.querySelector('[name=order]').value = p.order.join(',');
        saveScroll();
        f.submit();
    });
    document.addEventListener('dragend', hideIndic);
}
