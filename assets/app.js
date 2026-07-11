// Fonctions JS partagées entre plusieurs pages — chargé une seule fois depuis
// views/layout.php. Garder ce fichier minimal : une fonction ici doit être
// utilisée par au moins deux vues, sinon elle reste locale à sa vue.

// Normalise une chaîne pour une recherche insensible à la casse et aux accents.
function lassoNorm(s) {
    return s.toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '');
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
    const items  = Array.from(list.querySelectorAll(groupsFilter ? 'li:not(.cat-search-group):not(.cat-search-sens)' : 'li'));
    const groups   = groupsFilter ? Array.from(list.querySelectorAll('.cat-search-group')) : [];
    const sensHdrs = groupsFilter ? Array.from(list.querySelectorAll('.cat-search-sens')) : [];

    if (hydrateInitial) {
        const initItem = items.find(li => li.dataset.val === hidden.value);
        if (initItem) input.value = initItem.textContent;
    }

    function filterGroups() {
        groups.forEach(g => { let s = g.nextElementSibling, v = false; while (s && !s.classList.contains('cat-search-group') && !s.classList.contains('cat-search-sens')) { if (!s.hidden) v = true; s = s.nextElementSibling; } g.hidden = !v; });
        sensHdrs.forEach(h => { let s = h.nextElementSibling, v = false; while (s && !s.classList.contains('cat-search-sens')) { if (!s.hidden) v = true; s = s.nextElementSibling; } h.hidden = !v; });
    }
    function filter(q) {
        const nq = lassoNorm(q);
        items.forEach(li => { li.hidden = nq !== '' && !lassoNorm(li.textContent).includes(nq); });
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
            const cur = items.find(li => li.dataset.val === hidden.value);
            input.value = cur ? textFor(cur) : '';
        }, 150);
    });
    items.forEach(li => {
        li.addEventListener('mousedown', e => {
            e.preventDefault();
            hidden.value = li.dataset.val;
            input.value = textFor(li);
            list.hidden = true;
            if (onSelect) onSelect(li);
        });
    });
}
