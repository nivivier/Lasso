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
        <form method="post" action="?p=structure_message" class="form" id="contacter-form">
            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="structure_id" id="contacter-structure" value="">
            <?php // D'où l'on écrit : l'envoi y ramène plutôt que sur la fiche de
                  // la structure, pour qu'on enchaîne les lignes d'une campagne. ?>
            <?php if ($contacterRetourCampagne): ?>
            <input type="hidden" name="retour_campagne" value="<?= $contacterRetourCampagne ?>">
            <?php endif; ?>
            <div class="cadre-edit-head">
                <span class="cadre-edit-titre" id="contacter-titre">Contacter</span>
                <?php if ($modelesMessage): ?>
                <label class="inline contacter-modele">Charger un modèle
                    <select id="contacter-modele">
                        <option value="">—</option>
                        <?php foreach ($modelesMessage as $m): ?>
                            <option value="<?= (int) $m['id'] ?>"><?= e($m['nom']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <?php endif; ?>
            </div>

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
            <label>Destinataire
                <select name="contact_id" id="contacter-destinataire" required></select>
            </label>
            <p class="muted small contacter-fiche" id="contacter-fiche"></p>

            <?php // Projet concerné : c'est lui qui rattachera cette prise de contact
                  // à une campagne. Pré-coché si l'on écrit depuis une campagne, ou
                  // par le modèle qu'on charge. ?>
            <?php if (!empty($spectacleLabels)): ?>
            <label><span>Projet <?= info_tip("Les spectacles concernés par ce message. Une campagne compte ses structures contactées par ces projets.") ?></span>
                <span id="contacter-projets"><?= choix_coches_html('spectacle_ids', $spectacleLabels, $campagneProjets, 'Aucun') ?></span>
            </label>
            <?php endif; ?>

            <label>Objet <input name="sujet" id="contacter-sujet" value="" required></label>
            <?php // Ni infobulle sur les variables, ni exemple dans le champ : on
                  // écrit ici à UNE personne, pas à une liste. Les variables d'un
                  // modèle chargé restent résolues, mais silencieusement. ?>
            <label>Message
                <textarea name="corps" id="contacter-corps" rows="9" required></textarea>
            </label>

            <?php // Sur écran étroit, les deux commandes secondaires se réduisent à
                  // leur icône (.btn-compact-mobile) : à trois boutons libellés, la
                  // rangée passait à la ligne et « Envoyer » — le seul qu'on
                  // cherche — se retrouvait n'importe où. Le titre et l'aria-label
                  // portent le libellé, qui n'est plus lisible une fois masqué. ?>
            <div class="modal-actions">
                <button type="button" class="btn ghost btn-compact-mobile" id="contacter-annuler" title="Annuler" aria-label="Annuler"><?= icon('x') ?> <span class="btn-txt">Annuler</span></button>
                <button type="submit" name="section" value="brouillon" class="btn ghost btn-compact-mobile" formnovalidate title="Enregistrer le brouillon" aria-label="Enregistrer le brouillon"><?= icon('save') ?> <span class="btn-txt">Enregistrer le brouillon</span></button>
                <button type="submit" name="section" value="envoyer" data-confirm="Envoyer ce message ? Il partira immédiatement, avec une copie cachée à l'expéditeur, et sera consigné dans l'historique."><?= icon('mail') ?> Envoyer</button>
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
    var modeleSel = document.getElementById('contacter-modele');
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
        if (modeleSel) { modeleSel.value = ''; }
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
        if (modeleSel) { modeleSel.value = ''; }
        modal.setAttribute('hidden', '');
    }

    destinataire.addEventListener('change', function () {
        montrerContact();
        // Le « Bonjour {{prenom}} » d'un modèle doit suivre le destinataire :
        // sans ça, changer de personne après avoir chargé le modèle laissait le
        // prénom du précédent dans le message.
        if (modeleCharge) { appliquerModele(modeleCharge, false); }
    });

    if (modeleSel) modeleSel.addEventListener('change', function () {
        var m = modeles[this.value];
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
            }
        }
    });

    document.querySelectorAll('[data-contacter]').forEach(function (b) {
        b.addEventListener('click', function () { ouvrir(b.getAttribute('data-contacter')); });
    });
    document.getElementById('contacter-annuler').addEventListener('click', fermer);
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
