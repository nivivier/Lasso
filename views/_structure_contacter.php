<?php
// Fenêtre « Contacter » : écrire à UNE personne d'une structure, depuis l'une
// des boîtes d'expédition du booking. Le message part tout de suite (pas par la
// file d'attente des campagnes, qui s'étale sur des jours) et laisse une entrée
// d'historique de type « mailing » — c'est le même geste qu'un mailing, adressé
// à une personne plutôt qu'à une liste.
//
// UNE fenêtre pour PLUSIEURS structures : la fiche structure n'en propose
// qu'une, la page d'une campagne en propose autant qu'elle a de lignes. Le
// contenu est donc rempli à l'ouverture, à partir de $contacterCibles ; en
// rendre une par ligne aurait recopié la liste des modèles et des expéditeurs
// à chaque fois, et dupliqué les identifiants du document.
//
// Attendu de l'appelant :
//   $contacterCibles  [id structure => ['nom', 'contacts' (joignables), 'brouillon']]
//   $expediteurs, $modelesMessage, $spectacleLabels, $campagneProjets
//   $contacterRetourCampagne (facultatif) la campagne où revenir après l'envoi
// et, pour ouvrir : un élément portant data-contacter="<id structure>".
$contacterRetourCampagne = (int) ($contacterRetourCampagne ?? 0);
?>
<div id="contacter-modal" class="modal-overlay" hidden>
    <div class="modal-card modal-contacter">
        <form method="post" action="?p=structure_message<?= $depuisQs ?? '' ?>" class="form" id="contacter-form">
            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="structure_id" id="contacter-structure" value="">
            <?php // D'où l'on écrit : l'envoi y ramène plutôt que sur la fiche de
                  // la structure, pour qu'on enchaîne les lignes d'une campagne. ?>
            <?php if ($contacterRetourCampagne): ?>
            <input type="hidden" name="retour_campagne" value="<?= $contacterRetourCampagne ?>">
            <?php endif; ?>
            <?php // Barre du haut : de qui il s'agit, de quoi on part, et la
                  // sortie. Elle reste en place quand le formulaire défile :
                  // « Fermer » ne doit pas dépendre de l'endroit où l'on a
                  // laissé la molette. ?>
            <div class="modal-head">
                <span class="modal-titre" id="contacter-titre">Contacter</span>
                <?php // Un bouton qui ouvre la liste des modèles, et non un
                      // sélecteur toujours déployé : charger un modèle est une
                      // action, pas un réglage du message. Même menu que celui
                      // du déroulé d'une date (.feuille-menu) — il se referme
                      // au clic hors de lui, comme les autres. ?>
                <?php if ($modelesMessage): ?>
                <details class="feuille-menu contacter-modele" id="contacter-modele">
                    <summary class="btn ghost"><?= icon('file-text') ?> Charger un modèle</summary>
                    <div class="feuille-menu-panneau">
                        <?php foreach ($modelesMessage as $m): ?>
                        <button type="button" data-modele="<?= (int) $m['id'] ?>"><?= e($m['nom']) ?></button>
                        <?php endforeach; ?>
                    </div>
                </details>
                <?php endif; ?>
                <button type="button" class="btn ghost modal-fermer" id="contacter-fermer" title="Fermer" aria-label="Fermer"><?= icon('x') ?> Fermer</button>
            </div>

            <?php // De qui vers qui : les deux bouts de l'envoi se lisent d'une
                  // seule rangée, tant que la largeur le permet (.grid2-optional
                  // repasse en une colonne sous 640px). La fiche du destinataire
                  // reste sous son champ, à droite. ?>
            <div class="grid2-optional">
                <label>Expéditeur
                    <select name="expediteur_id" id="contacter-expediteur">
                        <option value="">Par défaut<?= mailing_expediteur_defaut_libelle() !== '' ? ' — ' . e(mailing_expediteur_defaut_libelle()) : '' ?></option>
                        <?php foreach ($expediteurs as $ex): ?>
                            <option value="<?= (int) $ex['id'] ?>"><?= e(mailing_expediteur_libelle($ex)) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <?php // Les destinataires sont posés à l'ouverture : ils dépendent de la
                      // structure choisie. Un contact repris d'une structure mère porte
                      // son nom — sans cela on écrirait à quelqu'un d'une autre
                      // organisation sans le savoir. ?>
                <div>
                    <label>Destinataire
                        <select name="contact_id" id="contacter-destinataire" required></select>
                    </label>
                    <p class="muted small contacter-fiche" id="contacter-fiche"></p>
                </div>
            </div>

            <label>Objet <input name="sujet" id="contacter-sujet" value="" required></label>
            <?php // Ni infobulle sur les variables, ni exemple dans le champ : on
                  // écrit ici à UNE personne, pas à une liste. Les variables d'un
                  // modèle chargé restent résolues, mais silencieusement. ?>
            <label>Message
                <textarea name="corps" id="contacter-corps" rows="9" required></textarea>
            </label>

            <?php // Le rattachement au projet vient APRÈS le message : on écrit
                  // d'abord, on range ensuite — et c'est la dernière décision
                  // avant d'envoyer. Ce qui se lisait en infobulle est dit à
                  // découvert : c'est ce rattachement, et lui seul, qui fait
                  // avancer la jauge d'une campagne. Pré-coché si l'on écrit
                  // depuis une campagne, ou par le modèle qu'on charge.
                  //
                  // <div> et non <label> : cliquer un label active son premier
                  // contrôle, ici la case « Tout » du groupe — qui cochait
                  // alors tous les projets (voir choix_coches_html()). ?>
            <?php // Le rattachement et les deux boutons tiennent la même rangée
                  // tant que la largeur le permet : c'est le pied de la fenêtre,
                  // le dernier regard avant d'envoyer. Il s'enroule en dessous
                  // sur écran étroit. ?>
            <div class="contacter-pied">
            <?php if (!empty($spectacleLabels)): ?>
            <div class="field-group"><span>Noter dans l'historique du projet</span>
                <span id="contacter-projets"><?= choix_coches_html('spectacle_ids', $spectacleLabels, $campagneProjets, 'Aucun') ?></span>
            </div>
            <?php endif; ?>

            <?php // Sur écran étroit, la commande secondaire se réduit à son icône
                  // (.btn-compact-mobile) : à trois boutons libellés, la rangée
                  // passait à la ligne et « Envoyer » — le seul qu'on cherche —
                  // se retrouvait n'importe où. Le titre et l'aria-label portent
                  // le libellé, qui n'est plus lisible une fois masqué.
                  // « Annuler » n'est plus ici : la barre du haut porte
                  // « Fermer », qui fait la même chose et se trouve toujours au
                  // même endroit. ?>
            <div class="modal-actions">
                <button type="submit" name="section" value="brouillon" class="btn ghost btn-compact-mobile" formnovalidate title="Enregistrer le brouillon" aria-label="Enregistrer le brouillon"><?= icon('save') ?> <span class="btn-txt">Enregistrer le brouillon</span></button>
                <button type="submit" name="section" value="envoyer" data-confirm="Envoyer ce message ? Il partira immédiatement, avec une copie cachée à l'expéditeur, et sera consigné dans l'historique."><?= icon('mail') ?> Envoyer</button>
            </div>
            </div>
        </form>
    </div>
</div>
<script nonce="<?= e(csp_nonce()) ?>">
(function () {
    var modal = document.getElementById('contacter-modal');
    if (!modal) return;
    var cibles = <?= json_encode(array_map(fn (array $c): array => [
        'nom' => (string) $c['nom'],
        'brouillon' => $c['brouillon'] ? [
            'contact_id' => (int) ($c['brouillon']['contact_id'] ?? 0),
            'expediteur_id' => (int) ($c['brouillon']['expediteur_id'] ?? 0),
            'sujet' => (string) ($c['brouillon']['sujet'] ?? ''),
            'corps' => (string) ($c['brouillon']['corps'] ?? ''),
        ] : null,
        'contacts' => array_map(fn ($ct) => [
            'id' => (int) $ct['id'],
            'prenom' => (string) $ct['prenom'],
            'nom' => (string) $ct['nom'],
            'role' => (string) $ct['role'],
            'email' => (string) $ct['email'],
            'telephone' => (string) $ct['telephone'],
            'langue' => (string) $ct['langue'],
            'booking' => (bool) $ct['est_booking'],
            'facturation' => (bool) $ct['est_administration'],
            'structure' => (string) ($ct['structure_nom'] ?? ''),
        ], $c['contacts']),
    ], $contacterCibles), JSON_UNESCAPED_UNICODE) ?>;
    var modeles = <?= json_encode(array_column($modelesMessage, null, 'id'), JSON_UNESCAPED_UNICODE) ?>;
    var projetsDefaut = <?= json_encode(array_map('intval', $campagneProjets)) ?>;

    var champStructure = document.getElementById('contacter-structure');
    var titre = document.getElementById('contacter-titre');
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
        return blocProjets ? blocProjets.querySelectorAll('input[type=checkbox][name="spectacle_ids[]"]') : [];
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
        if (m.spectacle_ids && m.spectacle_ids.length) {
            var dejaCoche = [].some.call(cases, function (c) { return c.checked; });
            if (!dejaCoche) {
                [].forEach.call(cases, function (c) {
                    c.checked = m.spectacle_ids.indexOf(parseInt(c.value, 10)) !== -1;
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
</script>
