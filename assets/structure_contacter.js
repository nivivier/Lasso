(function () {
// Fenêtre « Contacter » d'une structure (fiche structure, campagne de
// booking) : destinataires, modèles, brouillon, rattachement aux projets.
// Fichier à part pour être gardé en cache ; chargé à la place de l'ancien
// <script> en ligne (views/_structure_contacter.php), il lit la fenêtre rendue
// juste au-dessus. Valeurs du serveur : bloc #structure-contacter-donnees.
var DONNEES = JSON.parse((document.getElementById('structure-contacter-donnees') || {}).textContent || '{}');
(function () {
    var modal = document.getElementById('contacter-modal');
    if (!modal) return;
    var cibles = DONNEES.cibles;
    var modeles = DONNEES.modeles;
    var projetsDefaut = DONNEES.projetsDefaut;

    var cc = document.getElementById('contacter-cc');
    var cci = document.getElementById('contacter-cci');
    var blocCopies = document.getElementById('contacter-copies');
    var lienCopies = document.querySelector('.contacter-copies-lien');
    // Le lien s'efface quand les champs paraissent : il n'a plus rien à ouvrir,
    // et « Ajouter des copies » au-dessus d'un champ Cc ouvert se contredit.
    // (Le dépliage lui-même est le délégué générique data-show d'app.js.)
    if (lienCopies) { lienCopies.addEventListener('click', function () { lienCopies.hidden = true; }); }
    var champStructure = document.getElementById('contacter-structure');
    var titre = document.getElementById('contacter-titre');
    var avertissement = document.getElementById('contacter-avertissement');
    var destinataire = document.getElementById('contacter-destinataire');
    var fiche = document.getElementById('contacter-fiche');
    var expediteur = document.getElementById('contacter-expediteur');
    var sujet = document.getElementById('contacter-sujet');
    var corps = document.getElementById('contacter-corps');
    var modeleMenu = document.getElementById('contacter-modele');
    var blocProjets = document.getElementById('contacter-projets');

    // La cible en cours, le modèle chargé, et le texte qu'il a produit : de quoi
    // le RE-résoudre si l'on change de destinataire ensuite, et de quoi savoir
    // si l'on peut le faire sans écraser une phrase écrite à la main.
    var cible = null;
    var modeleCharge = null;
    var produit = { sujet: null, corps: null };
    var champs = modal.querySelectorAll('input:not([type=hidden]), select, textarea');
    var origine = [];

    function contactCourant() {
        if (!cible) return null;
        for (var i = 0; i < cible.contacts.length; i++) {
            if (String(cible.contacts[i].id) === destinataire.value) return cible.contacts[i];
        }
        return null;
    }

    // Le destinataire choisi s'affiche en entier : on écrit à une personne, pas
    // à une ligne de menu déroulant — son rôle et sa langue changent le ton du
    // message autant que son adresse.
    function montrerContact() {
        var c = contactCourant();
        if (!c) { fiche.textContent = ''; return; }
        var bouts = [c.email];
        // La structure d'origine en tête : c'est l'information qui change le
        // sens du message quand le contact vient de la structure mère.
        if (c.structure) bouts.unshift('Contact de « ' + c.structure + ' »');
        if (c.role) bouts.push(c.role);
        if (c.telephone) bouts.push(c.telephone);
        if (c.langue) bouts.push(c.langue);
        if (c.booking) bouts.push('booking');
        if (c.facturation) bouts.push('facturation');
        fiche.textContent = bouts.join(' · ');
    }

    // Les variables sont résolues ICI, pour qu'on relise le message tel qu'il
    // partira, destinataire compris. Le serveur repasse dessus à l'envoi, au cas
    // où (mailing_personnaliser(), lib/booking.php) — même liste de variables.
    function resoudre(texte, contact) {
        return String(texte || '')
            .split('{{prenom}}').join((contact && contact.prenom) || '')
            .split('{{nom_structure}}').join((cible && cible.nom) || '');
    }

    function appliquerModele(modele, forcer) {
        var c = contactCourant() || {};
        var nouveauSujet = resoudre(modele.sujet, c);
        var nouveauCorps = resoudre(modele.corps, c);
        // Sans « forcer » (changement de destinataire), on ne réécrit que ce
        // qu'on avait écrit soi-même : une phrase retouchée à la main reste
        // telle quelle, elle n'appartient plus au modèle.
        if (forcer || sujet.value === produit.sujet) { sujet.value = nouveauSujet; }
        if (forcer || corps.value === produit.corps) { corps.value = nouveauCorps; }
        produit.sujet = sujet.value;
        produit.corps = corps.value;
    }

    function casesProjets() {
        return blocProjets ? blocProjets.querySelectorAll('input[type=checkbox][name="projet_ids[]"]') : [];
    }
    function reinitProjets() {
        [].forEach.call(casesProjets(), function (c) {
            c.checked = projetsDefaut.indexOf(parseInt(c.value, 10)) !== -1;
        });
        majLibelleProjets();
    }
    // Cocher par code n'émet pas d'événement : le bouton du menu ne saurait pas
    // qu'il doit se renommer (lassoInitChoixCoches(), assets/app.js).
    function majLibelleProjets() {
        var bloc = blocProjets && blocProjets.querySelector('.choix-coches');
        if (bloc && bloc.majChoix) { bloc.majChoix(); }
    }

    function ouvrir(sid) {
        cible = cibles[sid];
        if (!cible) { return; }
        champStructure.value = sid;
        titre.textContent = 'Contacter ' + cible.nom;
        // Le libellé vient du serveur (STRUCTURE_STATUTS_LIBELLES) : un seul
        // endroit nomme les statuts, ici comme ailleurs.
        var aVerifier = cible.statut === DONNEES.statutAVerifier;
        avertissement.hidden = !aVerifier;
        if (aVerifier) {
            avertissement.textContent = 'Cette structure est notée « '
                + DONNEES.statutAVerifierLibelle
                + ' » : ses coordonnées ne sont peut-être plus à jour.';
        }
        // Destinataires de CETTE structure ; la requête met les « booking » en tête.
        destinataire.innerHTML = '';
        cible.contacts.forEach(function (ct) {
            var o = document.createElement('option');
            o.value = ct.id;
            o.textContent = ((ct.prenom + ' ' + ct.nom).trim() || ct.email)
                + (ct.structure ? ' — ' + ct.structure : '');
            destinataire.appendChild(o);
        });
        var b = cible.brouillon;
        if (b && b.contact_id) { destinataire.value = String(b.contact_id); }
        expediteur.value = b && b.expediteur_id ? String(b.expediteur_id) : '';
        sujet.value = b ? b.sujet : '';
        corps.value = b ? b.corps : '';
        // Les copies d'un brouillon : remises dans les champs ET dépliées, sinon
        // elles partiraient sans que personne les ait revues.
        cc.value = b ? (b.cc || '') : '';
        cci.value = b ? (b.cci || '') : '';
        blocCopies.hidden = cc.value === '' && cci.value === '';
        if (lienCopies) { lienCopies.hidden = !blocCopies.hidden; }
        if (modeleMenu) { modeleMenu.open = false; }
        modeleCharge = null;
        produit.sujet = null;
        produit.corps = null;
        reinitProjets();
        montrerContact();
        // L'état de référence pour « Annuler » est celui de CETTE ouverture.
        origine = Array.prototype.map.call(champs, function (c) { return c.value; });
        modal.removeAttribute('hidden');
        // preventScroll + remise à zéro : sur un écran court, le simple fait de
        // donner le focus à l'objet faisait défiler la fenêtre et emportait son
        // titre hors de vue à l'ouverture.
        sujet.focus({ preventScroll: true });
        modal.querySelector('.modal-card').scrollTop = 0;
    }

    // Annuler REND le message tel qu'il était à l'ouverture — c'est-à-dire le
    // brouillon enregistré, ou rien. Se contenter de masquer la fenêtre laissait
    // le texte dans les champs : en la rouvrant on le retrouvait, et on pouvait
    // croire qu'annuler l'avait enregistré. Rien n'est écrit en base tant qu'on
    // n'a pas cliqué « Enregistrer le brouillon ».
    function fermer() {
        Array.prototype.forEach.call(champs, function (c, i) { c.value = origine[i]; });
        modeleCharge = null;
        produit.sujet = null;
        produit.corps = null;
        if (modeleMenu) { modeleMenu.open = false; }
        modal.setAttribute('hidden', '');
    }

    destinataire.addEventListener('change', function () {
        montrerContact();
        // Le « Bonjour {{prenom}} » d'un modèle doit suivre le destinataire :
        // sans ça, changer de personne après avoir chargé le modèle laissait le
        // prénom du précédent dans le message.
        if (modeleCharge) { appliquerModele(modeleCharge, false); }
    });

    if (modeleMenu) modeleMenu.addEventListener('click', function (e) {
        var btn = e.target.closest('[data-modele]');
        if (!btn) { return; }
        modeleMenu.open = false;
        var m = modeles[btn.getAttribute('data-modele')];
        if (!m) { modeleCharge = null; return; }
        modeleCharge = m;
        appliquerModele(m, true);
        if (m.expediteur_id) { expediteur.value = m.expediteur_id; }
        // Projets du modèle : une valeur par défaut, qu'on peut encore changer.
        // On ne les impose que si rien n'est coché — un choix déjà fait à la
        // main, ou hérité d'une campagne, l'emporte sur le modèle.
        var cases = casesProjets();
        if (m.projet_ids && m.projet_ids.length) {
            var dejaCoche = [].some.call(cases, function (c) { return c.checked; });
            if (!dejaCoche) {
                [].forEach.call(cases, function (c) {
                    c.checked = m.projet_ids.indexOf(parseInt(c.value, 10)) !== -1;
                });
                majLibelleProjets();
            }
        }
    });

    document.querySelectorAll('[data-contacter]').forEach(function (b) {
        b.addEventListener('click', function () { ouvrir(b.getAttribute('data-contacter')); });
    });
    document.getElementById('contacter-fermer').addEventListener('click', fermer);
    modal.addEventListener('click', function (e) { if (e.target === modal) fermer(); });
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && !modal.hidden) fermer(); });
    // Entrée dans l'objet : passe au message plutôt que de soumettre. Sans ça,
    // le navigateur activerait le PREMIER bouton d'envoi du formulaire —
    // « Enregistrer le brouillon » — et enregistrerait sans qu'on l'ait demandé.
    sujet.addEventListener('keydown', function (e) {
        if (e.key === 'Enter') { e.preventDefault(); corps.focus(); }
    });
})();
})();
