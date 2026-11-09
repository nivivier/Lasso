<?php
// Comportements de la fiche d'un événement : cadres lecture/édition, déroulé
// de la feuille de route, sélecteurs de lieu et de structure, ventilation.
// Sorti de views/evenement.php, qui mêlait 270 lignes de script à son balisage.
//
// Le script reste une balise <script nonce> de la page : il lit des éléments
// rendus juste au-dessus. C'est sa PLACE dans le fichier qui change.
?>
<script nonce="<?= e(csp_nonce()) ?>">
(function () {
    // Cadres lecture/édition (Informations, Organisation, Localisation) :
    // script générique partagé (assets/app.js, window DOMContentLoaded) —
    // rien à faire ici.
    const suisaCheck = document.getElementById('suisa-applicable');
    if (suisaCheck) {
        const suisaChamps = document.getElementById('suisa-champs');
        suisaCheck.addEventListener('change', () => {
            suisaChamps.hidden = !suisaCheck.checked;
            suisaChamps.querySelectorAll('select, input').forEach(el => { el.disabled = !suisaCheck.checked; });
        });
    }

    // Case « Production externe » : cocher détache les prestations déjà liées
    // (côté serveur, route_evenement_production_externe_enregistrer()) — confirmation avant
    // de soumettre si des prestations existent. Décocher ne supprime rien.
    const prodCheck = document.getElementById('prod-externe-check');
    if (prodCheck) {
        const aDesPrestations = <?= json_encode((bool) array_filter($prestations)) ?>;
        prodCheck.addEventListener('change', () => {
            if (prodCheck.checked && aDesPrestations
                && !confirm('Cocher « Production externe » va supprimer les prestations déjà liées sur les fiches de salaire des employés de cet événement. Continuer ?')) {
                prodCheck.checked = false;
                return;
            }
            document.getElementById('prod-externe-form').requestSubmit();
        });
    }

    // Ligne de prestation (carte Employés) : les champs vivent dans des <td>
    // séparés (colonnes) mais partagent un même <form> via l'attribut form="…" —
    // on les manipule donc via la <tr> commune plutôt que via le <form>.
    //
    // Écouteurs DÉLÉGUÉS : une ligne ajoutée en arrière-plan (data-ajout) doit
    // calculer son total comme les autres, sans qu'on rebranche quoi que ce soit.
    const epfNum = v => parseFloat((v || '').toString().replace(',', '.')) || 0;
    const epfTotal = tr => {
        const unite  = tr.querySelector('.l-unite');
        const qte    = tr.querySelector('.l-qte');
        const choix  = tr.querySelector('.l-taux-choix');
        const manuel = tr.querySelector('.l-taux-manuel');
        const total  = tr.querySelector('.epf-total-live');
        if (!unite || !qte || !choix || !manuel) return; // ligne « configurez une unité… »
        manuel.style.display = choix.value === 'autre' ? '' : 'none';
        if (!total) return;
        const opt = unite.selectedOptions[0];
        const montant = (opt ? epfNum(opt.dataset.h) : 0) * epfNum(qte.value)
            * (choix.value === 'autre' ? epfNum(manuel.value) : epfNum(choix.value));
        total.textContent = montant > 0 ? (Math.round(montant * 100) / 100).toFixed(2) + ' CHF' : '—';
    };
    ['change', 'input'].forEach(evt => document.addEventListener(evt, ev => {
        const tr = ev.target.closest('.evenement-employes tbody tr');
        if (tr && ev.target.closest('.l-unite, .l-qte, .l-taux-choix, .l-taux-manuel')) epfTotal(tr);
    }));
    document.querySelectorAll('.evenement-employes tbody tr').forEach(epfTotal);
    // Une ligne arrivée après coup fait son premier calcul en entrant.
    document.addEventListener('lasso:ligne-ajoutee', ev => {
        if (ev.detail?.matches?.('.evenement-employes tbody tr')) epfTotal(ev.detail);
    });

    // Ligne de prestation : mode lecture (texte + crayon) tant que rien n'est
    // modifié ; au clic sur le crayon, celui-ci cède la place au trio
    // enregistrer / supprimer / annuler, et les champs s'ouvrent. Même
    // convention que les listes ordonnables (docs/UI.md §2d).
    const epfBascule = (tr, edition) => {
        tr.querySelectorAll('.epf-disp').forEach(el => { el.hidden = edition; });
        tr.querySelectorAll('.epf-editable').forEach(el => { el.hidden = !edition; });
        const crayon = tr.querySelector('.epf-edit-btn');
        if (crayon) crayon.hidden = edition;
    };
    document.addEventListener('click', ev => {
        const btn = ev.target.closest('.epf-edit-btn');
        if (btn) {
            const tr = btn.closest('tr');
            epfBascule(tr, true);
            const choix = tr.querySelector('.l-taux-choix');
            if (choix) choix.dispatchEvent(new Event('change'));
            const sel = tr.querySelector('.fiche-select-sm');
            if (sel) sel.focus();
            return;
        }
        // Annuler : referme la ligne sans rien enregistrer. Les champs gardent
        // ce qui y a été tapé — la page n'est pas rechargée —, mais rien n'est
        // parti : rouvrir la ligne montre donc la saisie abandonnée, et la
        // quitter pour de bon se fait en rechargeant, comme partout.
        const annul = ev.target.closest('.epf-annuler-btn');
        if (annul) { epfBascule(annul.closest('tr'), false); }
    });

    // L'employé qu'on vient de lier n'a pas de prestation : sa ligne s'ouvre
    // d'elle-même, puisque c'est pour la remplir qu'on l'a liée. Elle arrive
    // en lecture comme les autres (le partiel ne connaît qu'un état), et c'est
    // ce geste-ci qui l'ouvre — une ligne déjà à l'écran, elle, ne s'ouvre que
    // sous le crayon.
    document.addEventListener('lasso:ligne-ajoutee', ev => {
        const tr = ev.detail;
        if (!tr?.matches?.('.evenement-employes tbody tr')) return;
        if (!tr.querySelector('.epf-edit-btn')) return; // lecture seule, ou production externe
        epfBascule(tr, true);
        const sel = tr.querySelector('.fiche-select-sm');
        if (sel) sel.focus();
    });

    // Ne pas revenir en haut de la page après Ajouter un employé / Enregistrer /
    // Retirer (carte Employés) — on restaure la position de défilement au retour.
    const carteEmployes = document.getElementById('carte-employes');
    if (carteEmployes) {
        const scrollKey = 'evenement-scroll-<?= (int) $id ?>';
        carteEmployes.querySelectorAll('form').forEach(form => {
            form.addEventListener('submit', () => {
                sessionStorage.setItem(scrollKey, String(window.scrollY));
            });
        });
        const savedScroll = sessionStorage.getItem(scrollKey);
        if (savedScroll !== null) {
            sessionStorage.removeItem(scrollKey);
            window.addEventListener('load', () => window.scrollTo(0, parseInt(savedScroll, 10)));
        }
    }

    // Recherche de facture à lier : le champ se branche seul (data-cat-search),
    // il ne reste ici que l'exigence d'un CHOIX dans la liste avant l'envoi.
    document.querySelectorAll('.facture-search').forEach(wrap => {
        wrap.closest('form').addEventListener('submit', e => {
            const hidden = wrap.querySelector('.cat-search-val');
            const input = wrap.querySelector('.cat-search-input');
            if (!hidden.value) {
                input.setCustomValidity('Veuillez choisir une facture dans la liste');
                input.reportValidity();
                e.preventDefault();
            }
        });
    });

    // Lieu (base), recherche à la création (un seul lieu à ce stade) : widget
    // alimenté à la demande via ?p=lieux_json au premier focus (potentiellement
    // des milliers de lieux → pas d'injection dans la page). lassoInitCatSearch()
    // est appelée tout de suite (liste encore vide) — pas dans le .then() — pour
    // que le champ réagisse dès le premier focus/frappe/clic pendant que la
    // requête est en vol, au lieu de rester muet tant qu'elle n'a pas abouti
    // (c'était la cause du bug « il faut cliquer 2-3 fois »).
    const lieuWrapCreation = document.getElementById('evt-lieu-search');
    if (lieuWrapCreation && window.lassoInitCatSearch) {
        const lieuList = lieuWrapCreation.querySelector('.cat-search-list');
        const lieuInput = lieuWrapCreation.querySelector('.cat-search-input');
        lassoInitCatSearch(lieuWrapCreation, { clearHiddenOnInput: true });
        let lieuCharge = false;
        lieuInput.addEventListener('focus', function () {
            if (lieuCharge) { return; }
            lieuCharge = true;
            fetch('?p=lieux_json', { headers: { 'Accept': 'application/json' } })
                .then(r => r.json())
                .then(function (opts) {
                    const frag = document.createDocumentFragment();
                    opts.forEach(function (o) {
                        const li = document.createElement('li');
                        li.dataset.val = o.id;
                        li.textContent = o.nom;
                        frag.appendChild(li);
                    });
                    lieuList.appendChild(frag);
                    // Ré-applique le filtre courant : si l'utilisateur a déjà
                    // tapé pendant le chargement, les options qui viennent
                    // d'arriver doivent être filtrées tout de suite.
                    lieuInput.dispatchEvent(new Event('input'));
                })
                .catch(function () { lieuCharge = false; });
        });
    }

    // Carte « Organisation » (édition) : ajout/retrait de structures liées en
    // puces (« chips »), plus de distinction lieu/organisateur (voir
    // migration_66) — rien n'est envoyé au serveur avant « Enregistrer »,
    // contrairement aux anciennes routes lier/délier qui agissaient
    // immédiatement sur un seul lien à la fois. Une puce peut être marquée
    // « à facturer » (référence pour la facture et l'export SUISA) — une
    // seule à la fois, reflétée dans le champ caché facturation_id.
    const facturationHidden = document.getElementById('organisation-facturation-id');
    function definirFacturation(chips, id) {
        chips.querySelectorAll('.btn-tag-facturation').forEach(btn => {
            btn.classList.toggle('on', btn.closest('.badge').dataset.id === String(id));
        });
        if (facturationHidden) facturationHidden.value = id;
    }
    function ajouterChip(chips, id, label) {
        if (chips.querySelector('[data-id="' + id + '"]')) return;
        const chip = document.createElement('span');
        chip.className = 'badge';
        chip.dataset.id = id;
        const factBtn = document.createElement('button');
        factBtn.type = 'button'; factBtn.className = 'btn-tag-facturation';
        factBtn.title = 'Marquer comme structure à facturer / SUISA';
        factBtn.setAttribute('aria-label', 'Marquer comme structure à facturer');
        factBtn.innerHTML = <?= json_encode(icon('receipt-swiss-franc')) ?>;
        chip.appendChild(factBtn);
        chip.appendChild(document.createTextNode(' ' + label + ' '));
        const hidden = document.createElement('input');
        hidden.type = 'hidden'; hidden.name = 'structure_ids[]'; hidden.value = id;
        chip.appendChild(hidden);
        const removeBtn = document.createElement('button');
        removeBtn.type = 'button'; removeBtn.className = 'btn-tag-x chip-remove'; removeBtn.setAttribute('aria-label', 'Retirer'); removeBtn.textContent = '×';
        chip.appendChild(removeBtn);
        chips.appendChild(chip);
        // Première structure ajoutée à une liste vide : présélectionnée comme
        // référence facture/SUISA (aucune ambiguïté possible) ; au-delà, on ne
        // devine rien de plus — l'utilisateur choisit via l'étoile.
        if (chips.querySelectorAll('.badge').length === 1) {
            definirFacturation(chips, id);
        }
    }
    document.addEventListener('click', ev => {
        const factBtn = ev.target.closest('.btn-tag-facturation');
        if (factBtn) {
            definirFacturation(factBtn.closest('.tags-liste'), factBtn.closest('.badge').dataset.id);
            return;
        }
        const removeBtn = ev.target.closest('.chip-remove');
        if (removeBtn) {
            const chip = removeBtn.closest('.badge');
            const chips = chip.closest('.tags-liste');
            const etaitFacturation = chip.querySelector('.btn-tag-facturation')?.classList.contains('on');
            chip.remove();
            // La structure retirée était la référence facture/SUISA : reporte
            // automatiquement sur la première restante, plutôt que de laisser
            // silencieusement plus aucune référence choisie.
            if (etaitFacturation && chips) {
                const suivante = chips.querySelector('.badge');
                if (suivante) definirFacturation(chips, suivante.dataset.id);
                else if (facturationHidden) facturationHidden.value = '';
            }
        }
    });

    const structureWrap = document.getElementById('evt-structure-add-search');
    if (structureWrap && window.lassoInitCatSearch) {
        const structureChips = document.getElementById('organisation-structures-chips');
        const structureList = structureWrap.querySelector('.cat-search-list');
        const structureInput = structureWrap.querySelector('.cat-search-input');
        const structureHidden = structureWrap.querySelector('.cat-search-val');
        const structureNouveau = document.getElementById('organisation-nouveau');
        // lassoInitCatSearch() appelée tout de suite (liste encore vide, hormis
        // « + Nouvelle structure » déjà en dur), pas dans le .then() du fetch :
        // sinon le champ ne réagit à rien tant que ?p=lieux_json n'a pas abouti.
        lassoInitCatSearch(structureWrap, {
            clearHiddenOnInput: true,
            onSelect: li => {
                if (li.dataset.val === '__new__') {
                    if (structureNouveau) structureNouveau.hidden = false;
                } else {
                    ajouterChip(structureChips, li.dataset.val, li.textContent);
                }
                // Réinitialiser tout de suite la valeur cachée du widget de
                // recherche lui-même (pas seulement le texte affiché) : sinon
                // le blur qui suit (~150 ms, cf. lassoInitCatSearch) retrouve
                // l'ancien id et réaffiche son libellé dans le champ, comme si
                // le dernier élément ajouté restait « sélectionné ».
                structureHidden.value = '';
                structureInput.value = '';
            },
        });
        let structureChargee = false;
        structureInput.addEventListener('focus', function () {
            if (structureChargee) return;
            structureChargee = true;
            fetch('?p=lieux_json', { headers: { 'Accept': 'application/json' } })
                .then(r => r.json())
                .then(function (opts) {
                    const dejaLies = new Set([...structureChips.querySelectorAll('.badge')].map(b => b.dataset.id));
                    const frag = document.createDocumentFragment();
                    opts.forEach(function (o) {
                        if (dejaLies.has(String(o.id))) return;
                        const li = document.createElement('li');
                        li.dataset.val = o.id;
                        li.textContent = o.nom;
                        frag.appendChild(li);
                    });
                    structureList.appendChild(frag);
                    structureInput.dispatchEvent(new Event('input'));
                })
                .catch(function () { structureChargee = false; });
        });
    }
})();
</script>
