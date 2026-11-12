// Comportements du gabarit (views/layout.php) : tiroir de navigation sur
// téléphone, rail replié, fenêtre d'aperçu, barre d'actions du téléphone.
// Sorti de la page : 24 Ko renvoyés à CHAQUE requête, que le navigateur garde
// désormais en cache (suffixe ?v=<filemtime>, comme app.js). Chargé à la même
// place que l'ancien <script> en ligne, sans defer : il trouve le même DOM.
// Ses rares valeurs venues du serveur passent par le bloc JSON
// #lasso-gabarit-donnees, posé juste avant lui.
(function () {
    const body = document.body, burger = document.getElementById('burger'),
          close = document.getElementById('side-close'), scrim = document.getElementById('scrim');

    // Le rail garde sa position de défilement d'une page à l'autre. Chaque clic
    // recharge la page (pas de SPA) : sans ça, qui travaille dans Booking ou
    // dans les Paramètres — tout en bas de la colonne — remontait au Tableau de
    // bord à chaque navigation et devait redescendre.
    //
    // sessionStorage et non localStorage : la position appartient à cet onglet
    // et à cette session de travail. En navigation privée l'accès peut lever,
    // d'où les try/catch — le rail fonctionne sans, il oublie seulement.
    const railNav = document.querySelector('.side-nav');
    if (railNav) {
        const cleRail = 'lasso-rail-defilement';
        let memorise = null;
        try { memorise = sessionStorage.getItem(cleRail); } catch (e) {}
        if (memorise !== null) {
            railNav.scrollTop = parseInt(memorise, 10) || 0;
        } else {
            // Première visite de la session : on montre où l'on est plutôt que
            // le haut de la colonne — la page ouverte peut être sous la ligne
            // de flottaison (Paramètres, Recherche de fonds).
            const actif = railNav.querySelector('.nav-page.on, .nav-sous-page.on, .nav-volet.on');
            if (actif) { actif.scrollIntoView({ block: 'nearest' }); }
        }
        railNav.addEventListener('scroll', () => {
            try { sessionStorage.setItem(cleRail, String(Math.round(railNav.scrollTop))); } catch (e) {}
        }, { passive: true });
    }
    function toggle(open) {
        body.classList.toggle('nav-open', open);
        burger.setAttribute('aria-expanded', open ? 'true' : 'false');
    }
    // (Plus de mesure du bandeau : il n'est plus collant, donc l'en-tête d'un
    // tableau se colle au bord de la fenêtre et n'a plus de décalage à
    // calculer — voir .table-scroll .list thead th dans assets/css/.)

    burger.addEventListener('click', () => toggle(!body.classList.contains('nav-open')));
    close.addEventListener('click', () => toggle(false));
    scrim.addEventListener('click', () => toggle(false));

    // L'action principale de la page remonte dans la barre supérieure sur
    // téléphone (docs/UI.md § 1). Laquelle : le bouton MIS EN ÉVIDENCE de la
    // barre d'outils — un `.btn` sans `.ghost` —, il y en a un ou aucun. Le
    // bouton est DÉPLACÉ, pas dupliqué : un second exemplaire se serait
    // désynchronisé du premier (libellé, lien, droits) au premier écran qui
    // change le sien.
    //
    // Pourquoi en JavaScript, alors que l'application en met le moins possible :
    // la barre est écrite AVANT la vue (elle est dans le gabarit), donc le
    // serveur ne connaît pas encore le bouton quand il la rend. Et le poser en
    // CSS ne marche pas : `.toolbar` ouvre un contexte d'empilement (z-index:2),
    // dont un enfant en position:fixed ne sort pas — il serait peint SOUS la
    // barre quel que soit son z-index. Sans script, le bouton reste donc à sa
    // place d'origine dans la barre d'outils : la page garde exactement le
    // comportement qu'elle avait.
    //
    // Où le chercher : la rangée d'actions d'une barre d'outils ou d'un en-tête
    // de page — et rien d'autre, surtout pas « un .btn quelque part dans
    // l'en-tête » : le bouton « Appliquer » d'un panneau d'entonnoir serait
    // parti dans la barre. Un menu déroulant (le « + » du tableau de bord) a
    // son bouton pour <summary> : c'est alors le <details> entier qui monte,
    // son panneau avec lui.
    //
    // ⚠️ Un LIEN ou un menu, jamais un <button> : sur un écran qui est un
    // formulaire, « Enregistrer » est lui aussi mis en évidence dans l'en-tête
    // (entete_form_actions_html()), et le monter dans la barre le séparerait
    // d'« Annuler » et de « Supprimer », qui forment un trio. Ce que la barre
    // prend, c'est l'action qui mène AILLEURS — créer, importer, choisir.
    //
    // ⚠️ Les créneaux sont essayés UN À UN, du plus précis au plus large, et
    // non réunis en une seule liste de sélecteurs : querySelector('a, b') rend
    // le premier élément dans l'ordre du DOCUMENT, pas le premier sélecteur qui
    // trouve. Comme le bandeau de page précède la barre d'outils, une page qui
    // aurait un bouton aux deux endroits aurait laissé monter celui du bandeau,
    // l'inverse de l'ordre écrit ici.
    const slotAction = document.getElementById('mbar-action-slot');
    const creneaux = ['.toolbar > .head-actions', '.page-head-band + .head-actions', '.page-head > .head-actions', '.page-head']
        .flatMap(c => [c + ' > a.btn:not(.ghost)', c + ' > .menu-deroulant > summary.btn:not(.ghost)']);
    const trouve = creneaux.reduce((trouvee, sel) => trouvee || document.querySelector(sel), null);
    const actionPrinc = trouve && trouve.tagName === 'SUMMARY' ? trouve.parentElement : trouve;
    if (slotAction && actionPrinc) {
        // Ancre invisible laissée à la place d'origine : au-delà du seuil, le
        // bouton doit revenir EXACTEMENT là où il était, et non à la fin de la
        // rangée d'actions — « Modifier » y est toujours le dernier.
        const ancre = document.createComment('action principale');
        actionPrinc.after(ancre);
        const etroit = matchMedia('(max-width: 800px)');
        const placer = () => {
            if (etroit.matches) { slotAction.appendChild(actionPrinc); }
            else { ancre.parentNode.insertBefore(actionPrinc, ancre); }
            actionPrinc.classList.toggle('mbar-action', etroit.matches);
        };
        placer();
        etroit.addEventListener('change', placer);
    }

    // Les actions de la page remontent dans le bandeau, à droite du surtitre :
    // c'est là qu'on les cherche, et la barre d'outils d'une liste n'a plus à
    // porter deux choses de nature différente — ce qui FILTRE ce qu'on voit, et
    // ce qui AGIT dessus (docs/UI.md § 1).
    //
    // Déplacement et non réécriture des trente vues concernées : chacune rend
    // déjà son bloc .head-actions au même endroit, et le bandeau est rendu
    // AVANT le contenu — il ne peut pas connaître des boutons qui n'existent pas
    // encore. L'ancre laissée en place sert au retour sous 800px, où le bandeau
    // n'a pas la largeur de les accueillir. Ce bloc vient APRÈS celui de la
    // barre mobile, qui doit repérer l'action principale là où la vue l'a mise.
    // Même mécanique pour la recherche de la page, qui monte à droite du TITRE
    // (une ligne plus bas que les actions) : elle appartient à ce qu'on
    // regarde. Les deux partagent le même interrupteur de largeur.
    const large = matchMedia('(min-width: 801px)');
    const remonter = (creneau, bloc) => {
        if (!creneau || !bloc) return;
        const ancre = document.createComment('bloc remonté dans le bandeau');
        bloc.after(ancre);
        const placer = () => {
            if (large.matches) { creneau.appendChild(bloc); }
            else { ancre.parentNode.insertBefore(bloc, ancre); }
        };
        placer();
        large.addEventListener('change', placer);
    };
    // Une page SANS barre d'outils pose son bloc juste après le bandeau : il
    // monte de la même façon. Essayé en second, pour la même raison que les
    // créneaux de la barre mobile ci-dessus — l'ordre écrit, pas celui du DOM.
    const blocActions = document.querySelector('.module-content .toolbar > .head-actions')
        ?? document.querySelector('.page-head-band + .head-actions');
    // La bascule Liste/Carte ne monte PAS avec les actions : elle ne crée rien,
    // elle change ce qu'on voit — c'est l'étage 2 (docs/UI.md § 1). Sortie du
    // bloc avant son déplacement, elle reste dans la barre d'outils, poussée à
    // droite par le CSS.
    const bascule = blocActions?.querySelector('.seg-picker');
    if (bascule && blocActions) { blocActions.parentNode.appendChild(bascule); }
    remonter(document.getElementById('page-head-actions'), blocActions);

    // La barre d'outils — recherche, bouton de filtres, bascule de vue —
    // monte sur la ligne du TITRE, au milieu du vide entre lui et les
    // actions : l'en-tête perd alors son second étage, et ce qui filtre
    // reste à portée de ce qu'il filtre. Elle n'y monte que si la ligne la
    // tient ; sinon elle redescend exactement où la vue l'avait mise.
    //
    // MESURÉ, et non décidé par un point de rupture : la place dépend du
    // titre (« Correspondance avec les structures » contre « Projets »), du
    // nombre de boutons d'action et du contenu de la barre elle-même — trois
    // choses qu'une media query ignore. Le test se fait sur la ligne du titre
    // une fois la barre posée dedans : débordement horizontal, ou rangée
    // d'actions qui passe à deux lignes (.page-head-actions a flex-wrap:wrap,
    // elle se replie avant de faire déborder quoi que ce soit).
    //
    // ⚠️ La barre est TOUJOURS redescendue avant d'être mesurée : montée, elle
    // occupe elle-même le vide qu'on cherche à mesurer, et la réponse serait
    // « ça tient » à toutes les largeurs. Le va-et-vient ne coûte qu'un calcul
    // de mise en page, jamais un affichage intermédiaire — rien n'est peint
    // entre les deux dans la même tâche.
    const creneauOutils = document.getElementById('page-head-outils');
    // La ligne est déduite du créneau, et non cherchée dans .page-head-band :
    // les Paramètres rendent le même en-tête par _param_tabs.php, dans une
    // autre enveloppe.
    const ligneTitre = creneauOutils && creneauOutils.closest('.page-head-titre-page');
    const barreOutils = document.querySelector('.module-content > .module-content-inner > .toolbar');
    if (creneauOutils && ligneTitre && barreOutils) {
        const ancreOutils = document.createComment('barre d\'outils remontée sur le titre');
        barreOutils.after(ancreOutils);
        const actionsLigne = ligneTitre.querySelector('.page-head-actions');
        const placerOutils = () => {
            ancreOutils.parentNode.insertBefore(barreOutils, ancreOutils);
            if (!large.matches) return;
            const hActions = actionsLigne ? actionsLigne.offsetHeight : 0;
            creneauOutils.appendChild(barreOutils);
            const deborde = ligneTitre.scrollWidth > ligneTitre.clientWidth + 1
                || (actionsLigne && actionsLigne.offsetHeight > hActions);
            if (deborde) { ancreOutils.parentNode.insertBefore(barreOutils, ancreOutils); }
        };
        placerOutils();
        // Les largeurs ne sont justes qu'une fois les polices chargées : un
        // titre rendu dans la police de secours est plus étroit, et la barre
        // serait montée sur une ligne qui ne la tiendra pas.
        if (document.fonts && document.fonts.ready) { document.fonts.ready.then(placerOutils); }
        large.addEventListener('change', placerOutils);
        // Coalescé en requestAnimationFrame, comme le panneau de filtres de
        // assets/app.js : glisser le bord de la fenêtre déclenche des dizaines
        // d'événements par seconde, et chacun coûte ici deux mises en page.
        let rafOutils = false;
        window.addEventListener('resize', () => {
            if (rafOutils) return;
            rafOutils = true;
            requestAnimationFrame(() => { rafOutils = false; placerOutils(); });
        });
    }

    // Une FICHE range son en-tête comme une liste : le lien de retour et les
    // actions sur la ligne haute, le titre en dessous. Le retour est un frère
    // du bloc de titre dans le balisage (et un <a>, donc rien ne peut s'y
    // nicher) : on le fait entrer dans le bloc, et une grille remet chacun à
    // sa place. Le jour où une vue met déjà son retour à l'intérieur
    // (?p=evenement), il n'y a rien à faire.
    const bande = document.querySelector('.module-content-inner > .page-head-bande');
    if (bande) {
        const retour = bande.previousElementSibling;
        if (retour && retour.classList.contains('back-link')) {
            bande.insertBefore(retour, bande.firstChild);
        }
        if (bande.querySelector(':scope > .back-link')) {
            bande.classList.add('page-head-bande-range');
        }
    }

    // Les actions secondaires passent derrière les trois points. Une seule
    // reste dehors : l'action PRINCIPALE — le bouton plein, ou à défaut le
    // dernier du bloc, puisque la convention de l'application met l'action
    // majeure en fin de rangée (docs/UI.md § 1). Deux actions de même poids
    // côte à côte, c'est deux fois rien qui se dispute l'œil.
    //
    // ⚠️ Déplacement des nœuds EUX-MÊMES, écouteurs compris (le « + Écriture
    // manuelle » de la compta est un bouton piloté par la vue) : rien n'est
    // recréé, seules les classes de bouton tombent — dans le panneau, une
    // entrée est une ligne de menu, pas un bouton de plus.
    const menuPlus = document.getElementById('page-head-plus');
    const panneauPlus = document.getElementById('page-head-plus-panneau');
    if (blocActions && menuPlus && panneauPlus) {
        const depouiller = (el) => {
            el.classList.remove('btn', 'ghost', 'btn-sm', 'btn-compact', 'icon-only', 'mbar-action');
            el.querySelectorAll('.btn-compact-break').forEach(br => br.remove());
        };
        const enfants = Array.from(blocActions.children);
        // Un menu déroulant déjà posé par la vue reste dehors : il porte ses
        // propres entrées, et l'imbriquer dans un second n'aurait pas de sens.
        const menusVue = enfants.filter(el => el.classList.contains('menu-deroulant'));
        const deplacables = enfants.filter(el => !el.classList.contains('menu-deroulant'));
        // L'action principale reste dehors elle aussi : le bouton PLEIN, ou à
        // défaut le dernier du bloc. ⚠️ Sauf quand la vue a déjà son menu : il
        // EST l'action principale (« Nouvelle pièce » sur ?p=pieces), et tout
        // le reste part derrière les trois points — sans cette exception, un
        // bouton secondaire resté seul se prenait pour le principal et
        // l'archive de l'année trônait à côté du menu.
        //
        // ⚠️ Sur téléphone, l'action principale est DÉJÀ partie dans la barre
        // supérieure (plus haut) quand on arrive ici : elle n'est plus dans le
        // bloc. Sans en tenir compte, l'action secondaire restée seule s'y
        // prenait pour la principale et ne passait jamais derrière « … » —
        // « Exporter pour certificats » sur ?p=fiches, « Écriture manuelle »
        // sur ?p=compta_ecritures, « Aperçu » sur ?p=compta_bilan.
        const principaleMontee = actionPrinc && !blocActions.contains(actionPrinc) && slotAction.contains(actionPrinc);
        const plein = deplacables.filter(el =>
            (el.classList.contains('btn') && !el.classList.contains('ghost')) || el.querySelector('.btn:not(.ghost)')
        ).pop();
        // `data-plus` : la vue range d'office cette action derrière « … ». Sans
        // action pleine, la règle « le dernier du bloc » en faisait sinon la
        // principale (« Aperçu » sur ?p=compta_bilan).
        const candidates = deplacables.filter(el => !el.hasAttribute('data-plus'));
        const principale = principaleMontee ? null
            : plein || (menusVue.length ? null : candidates[candidates.length - 1]);
        const secondaires = deplacables.filter(el => el !== principale);
        if (secondaires.length) {
            secondaires.forEach(el => {
                depouiller(el);
                el.querySelectorAll('.btn').forEach(depouiller);
                panneauPlus.appendChild(el);
            });
            menuPlus.hidden = false;
            // Sur téléphone, « … » rejoint l'action principale dans la barre
            // supérieure, à sa droite : toutes les actions de la page au même
            // endroit, en haut, quelle que soit la page (docs/UI.md § 1).
            // Ancre laissée à sa place pour le retour au-dessus de 800px.
            if (slotAction) {
                const ancrePlus = document.createComment('menu « … » du bandeau');
                menuPlus.after(ancrePlus);
                const etroitPlus = matchMedia('(max-width: 800px)');
                const placerPlus = () => {
                    menuPlus.open = false;
                    if (etroitPlus.matches) { slotAction.appendChild(menuPlus); }
                    else { ancrePlus.parentNode.insertBefore(menuPlus, ancrePlus); }
                    menuPlus.classList.toggle('mbar-plus', etroitPlus.matches);
                };
                placerPlus();
                etroitPlus.addEventListener('change', placerPlus);
            }
        }
    }

    // Pastille utilisateur : ouvre/ferme le menu au clic, ferme si clic dehors.
    const avatarBtn  = document.getElementById('side-avatar-btn');
    const avatarMenu = document.getElementById('side-avatar-menu');
    avatarBtn.addEventListener('click', (e) => {
        e.stopPropagation();
        const open = avatarMenu.hasAttribute('hidden');
        avatarMenu.toggleAttribute('hidden', !open);
        avatarBtn.setAttribute('aria-expanded', open ? 'true' : 'false');
    });
    document.addEventListener('click', () => {
        avatarMenu.setAttribute('hidden', '');
        avatarBtn.setAttribute('aria-expanded', 'false');
    });

    // Infobulles « i » (.info-tip) : tap pour basculer — indispensable sur
    // mobile où :hover ne s'applique pas. Une seule ouverte à la fois, fermeture
    // au clic dehors ou à Echap.
    document.addEventListener('click', e => {
        const tip = e.target.closest('.info-tip');
        document.querySelectorAll('.info-tip.open').forEach(t => { if (t !== tip) t.classList.remove('open'); });
        if (tip) {
            // Empêche le <label> englobant de transférer le clic à son champ
            // (sinon ce clic « fantôme » referme aussitôt la bulle qu'on ouvre).
            e.preventDefault();
            e.stopPropagation();
            tip.classList.toggle('open');
        }
    });
    document.addEventListener('keydown', e => {
        if (e.key === 'Escape') document.querySelectorAll('.info-tip.open').forEach(t => t.classList.remove('open'));
    });

    // Messages flottants : disparition automatique après 3 s
    document.querySelectorAll('.flash').forEach(el => {
        setTimeout(() => { el.classList.add('flash-out'); setTimeout(() => el.remove(), 400); }, 3000);
    });

    // Lignes cliquables (souris + clavier). Un clic sur un lien/bouton/case à
    // cocher dans la ligne garde son comportement propre — form inclus (ex.
    // formulaire d'ajout d'étiquette par ligne, ?p=structures, ou les
    // formulaires déjà présents dans les lignes de ?p=projets) : sans ça,
    // un clic dans un espace du formulaire hors bouton/champ (padding entre
    // deux champs, etc.) déclenchait quand même la navigation de la ligne.
    // .cat-search-list répétée à part (déjà couverte par closest('form') vu
    // qu'elle y est nichée aujourd'hui) : au cas où une future liste de
    // suggestions apparaisse un jour hors d'un <form> dans une ligne.
    // Destination d'une ligne cliquable : data-href s'il est posé, sinon le lien
    // de titre que la ligne contient déjà. ?p=structures ne pose plus l'attribut
    // — il recopiait ce href à l'identique sur 2959 lignes — et les listes qui
    // n'ont pas de .titre-lien continuent de le fournir.
    function go(el) {
        const u = el.getAttribute('data-href') || el.querySelector('a.titre-lien')?.getAttribute('href');
        if (u) location.href = u;
    }
    document.querySelectorAll('tr.row-link').forEach(row => {
        // .plan-grip : poignée de glisser-déposer (?p=projets). Elle portait
        // un onclick="event.stopPropagation()" ; les attributs de gestionnaire
        // ayant été supprimés pour permettre le durcissement de la CSP, son
        // exclusion se déclare ici, comme celle des autres éléments interactifs.
        // .menu-deroulant : un menu posé dans une ligne (« Synchroniser »,
        // ?p=projets). Son <summary> n'est ni un <a> ni un <button> — sans
        // cette exclusion, l'ouvrir naviguait vers la fiche.
        row.addEventListener('click', e => { if (!e.target.closest('a,button,input,form,.cat-search-list,.plan-grip,.menu-deroulant')) go(row); });
        row.addEventListener('keydown', e => {
            // e.target !== row : la ligne ne s'active au clavier que si c'est
            // ELLE qui a le focus. Sans ce test, une frappe dans un champ de la
            // ligne remontait jusqu'ici — taper une espace dans le champ
            // « nouvelle étiquette » de ?p=structures naviguait vers la fiche
            // au lieu d'écrire l'espace, et les étiquettes en deux mots étaient
            // impossibles à saisir. Le gestionnaire de clic juste au-dessus
            // faisait déjà cette exclusion ; celui-ci l'avait oubliée.
            if (e.target !== row) return;
            if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); go(row); }
        });
    });

    // Clic sur le texte résumé → bascule résumé ↔ texte brut complet (toutes pages).
    // Une seule fonction pour la cellule et pour la colonne entière : deux
    // chemins vers le même état finiraient par en donner deux différents.
    const basculerTexte = (td, expanded) => {
        const txt = td.querySelector('.texte-cell-txt');
        if (!txt) return;
        td.classList.toggle('expanded', expanded);
        txt.textContent = expanded ? td.title : txt.dataset.summary;
    };
    document.addEventListener('click', e => {
        const td = e.target.closest('.compta-lettrage .texte-cell');
        if (!td || e.target.closest('a,button')) return;
        basculerTexte(td, !td.classList.contains('expanded'));
    });
    // Et le bouton d'en-tête, qui fait d'un coup ce que la colonne fait une
    // cellule à la fois. Il regarde l'état réel des cellules plutôt que de
    // garder le sien : une cellule dépliée à la main ne doit pas le
    // désynchroniser. Tant qu'il en reste une repliée, il déplie tout.
    document.addEventListener('click', e => {
        const btn = e.target.closest('.texte-tout-btn');
        if (!btn) return;
        const cells = [...document.querySelectorAll('.compta-lettrage .texte-cell')];
        const tout = cells.some(td => !td.classList.contains('expanded'));
        cells.forEach(td => basculerTexte(td, tout));
        btn.classList.toggle('on', tout);
        btn.title = tout ? 'Tout résumer' : 'Tout détailler';
        btn.setAttribute('aria-label', btn.title);
        btn.innerHTML = btn.dataset[tout ? 'icoFold' : 'icoUnfold'];
    });

    // Fenêtre d'aperçu (liens [data-preview]). DEUX formats, et pas un de plus :
    //   « a4 » (défaut) — un document destiné au papier : facture, fiche de
    //          salaire, certificat, bilan. La fenêtre a la largeur d'une feuille.
    //   « ajuste »      — un contenu qui n'est pas une feuille : le tableau de
    //          l'export SUISA, par exemple. La fenêtre prend la place disponible.
    // Le format se déclare sur le lien (data-preview="ajuste"), et non dans la
    // page cible : la fenêtre s'ouvre avant que l'iframe ait chargé, elle doit
    // donc connaître sa taille tout de suite.
    const previewModal = document.getElementById('preview-modal');
    const previewFrame = document.getElementById('preview-modal-frame');
    const previewClose = document.getElementById('preview-modal-close');
    function openPreview(url, format) {
        previewModal.classList.toggle('preview-ajuste', format === 'ajuste');
        previewFrame.style.height = '';   // remis à la hauteur du format courant
        previewFrame.src = url;
        previewClose.hidden = false; // masqué au chargement si la page a une barre d'outils
        previewModal.removeAttribute('hidden');
        document.body.style.overflow = 'hidden';
    }
    function closePreview() {
        previewModal.setAttribute('hidden', '');
        previewFrame.src = '';
        previewFrame.style.height = '';
        document.body.style.overflow = '';
    }
    // Format « ajuste » : la hauteur du cadre suit celle du contenu, plafonnée à
    // la place disponible. Sans cette mesure, l'iframe occuperait toute la
    // fenêtre même pour trois lignes de tableau — un cadre vide sous le contenu.
    function ajusterHauteur() {
        if (!previewModal.classList.contains('preview-ajuste')) return;
        try {
            const doc = previewFrame.contentDocument;
            const dispo = previewModal.clientHeight - 64; // padding 32px en haut et en bas
            previewFrame.style.height = Math.min(doc.documentElement.scrollHeight + 2, dispo) + 'px';
        } catch (err) {}
    }
    document.addEventListener('click', e => {
        const a = e.target.closest('a[data-preview]');
        if (!a || e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey) return;
        e.preventDefault();
        openPreview(a.href, a.dataset.preview);
    });
    previewClose.addEventListener('click', closePreview);
    previewModal.addEventListener('click', e => { if (e.target === previewModal) closePreview(); });
    document.addEventListener('keydown', e => {
        if (e.key === 'Escape' && !previewModal.hasAttribute('hidden')) closePreview();
    });
    // « Fermer » rejoint la barre d'outils de la page affichée (même origine →
    // accès DOM autorisé), tout à droite : c'est un bouton comme les autres, à
    // la fin de la rangée des actions, et non une pastille posée par-dessus le
    // document. Il est injecté d'ici plutôt qu'écrit dans les huit vues
    // d'impression : ces pages s'ouvrent aussi seules, hors de la fenêtre
    // d'aperçu, où « Fermer » n'aurait rien à fermer.
    // Le SVG complet du bouton, rendu par le gabarit (views/layout.php) : la
    // page d'aperçu n'a pas le sprite, une icône en <use> n'y dessinerait rien.
    const fermerHtml = JSON.parse(document.getElementById('lasso-gabarit-donnees')?.textContent || '{}').fermerHtml || '';
    previewFrame.addEventListener('load', () => {
        ajusterHauteur();
        try {
            const doc = previewFrame.contentDocument;
            const barre = doc.querySelector('.print-toolbar');
            if (barre && !barre.querySelector('.print-toolbar-fermer')) {
                // Inséré en HTML, et non monté nœud par nœud : le bouton vient
                // tout fait du serveur, classes et <span class="btn-txt">
                // compris — c'est ce span qui laisse « Fermer » s'effacer sur
                // un téléphone, et le réécrire ici le ferait diverger.
                barre.insertAdjacentHTML('beforeend', fermerHtml);
                barre.querySelector('.print-toolbar-fermer')
                     .addEventListener('click', closePreview);
            }
            previewClose.hidden = !!barre;
            doc.addEventListener('keydown', ev => {
                if (ev.key === 'Escape') { ev.stopPropagation(); closePreview(); }
            }, true);
        } catch(err) {}
    });
})();
