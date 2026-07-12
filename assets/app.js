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
});
