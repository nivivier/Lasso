<?php
// Fenêtre « Contacter » d'une fiche structure : écrire à UNE personne de cette
// structure, depuis l'une des boîtes d'expédition du booking. Le message part
// tout de suite (pas par la file d'attente des campagnes, qui s'étale sur des
// jours) et laisse une entrée d'historique de type « mailing » — c'est le même
// geste qu'un mailing, adressé à une personne plutôt qu'à une liste.
//
// Attendu de l'appelant (views/structure_form.php) : $structure, $sid,
// $contactsJoignables, $expediteurs, $modelesMessage, $brouillon — et le bouton
// #contacter-btn qui l'ouvre. N'est inclus que si la structure est contactable
// (structure_contact_impossible_raison(), lib/booking.php).
$contactDefaut = $brouillon && $brouillon['contact_id']
    ? (int) $brouillon['contact_id']
    : (int) ($contactsJoignables[0]['id'] ?? 0); // la requête met les « booking » en tête
$expediteurDefautId = $brouillon && $brouillon['expediteur_id'] ? (int) $brouillon['expediteur_id'] : 0;
?>
<div id="contacter-modal" class="modal-overlay" hidden>
    <div class="modal-card modal-contacter">
        <form method="post" action="?p=structure_message" class="form" id="contacter-form">
            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="structure_id" value="<?= $sid ?>">
            <div class="cadre-edit-head">
                <span class="cadre-edit-titre">Contacter <?= e((string) $structure['nom']) ?></span>
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
                            <option value="<?= (int) $ex['id'] ?>" <?= $expediteurDefautId === (int) $ex['id'] ? 'selected' : '' ?>><?= e(mailing_expediteur_libelle($ex)) ?></option>
                        <?php endforeach; ?>
                    </select>
            </label>
            <label>Destinataire
                <select name="contact_id" id="contacter-destinataire" required>
                    <?php foreach ($contactsJoignables as $c): ?>
                        <option value="<?= (int) $c['id'] ?>" <?= $contactDefaut === (int) $c['id'] ? 'selected' : '' ?>>
                            <?= e(trim((string) $c['prenom'] . ' ' . (string) $c['nom']) ?: (string) $c['email']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
            <p class="muted small contacter-fiche" id="contacter-fiche"></p>

            <label>Objet <input name="sujet" id="contacter-sujet" value="<?= e((string) ($brouillon['sujet'] ?? '')) ?>" required></label>
            <?php // Ni infobulle sur les variables, ni exemple dans le champ : on
                  // écrit ici à UNE personne, pas à une liste. Les variables d'un
                  // modèle chargé restent résolues, mais silencieusement. ?>
            <label>Message
                <textarea name="corps" id="contacter-corps" rows="9" required><?= e((string) ($brouillon['corps'] ?? '')) ?></textarea>
            </label>

            <div class="modal-actions">
                <button type="button" class="btn ghost" id="contacter-annuler"><?= icon('x') ?> Annuler</button>
                <button type="submit" name="section" value="brouillon" class="btn ghost" formnovalidate><?= icon('save') ?> Enregistrer le brouillon</button>
                <button type="submit" name="section" value="envoyer" data-confirm="Envoyer ce message ? Il partira immédiatement, avec une copie cachée à l'expéditeur, et sera consigné dans l'historique."><?= icon('mail') ?> Envoyer</button>
            </div>
        </form>
    </div>
</div>
<script nonce="<?= e(csp_nonce()) ?>">
(function () {
    var modal = document.getElementById('contacter-modal');
    var ouvrir = document.getElementById('contacter-btn');
    if (!modal || !ouvrir) return;
    var contacts = <?= json_encode(array_column(array_map(fn ($c) => [
        'id' => (int) $c['id'],
        'prenom' => (string) $c['prenom'],
        'nom' => (string) $c['nom'],
        'role' => (string) $c['role'],
        'email' => (string) $c['email'],
        'telephone' => (string) $c['telephone'],
        'langue' => (string) $c['langue'],
        'booking' => (bool) $c['est_booking'],
        'facturation' => (bool) $c['est_administration'],
    ], $contactsJoignables), null, 'id'), JSON_UNESCAPED_UNICODE) ?>;
    var modeles = <?= json_encode(array_column($modelesMessage, null, 'id'), JSON_UNESCAPED_UNICODE) ?>;
    var nomStructure = <?= json_encode((string) $structure['nom'], JSON_UNESCAPED_UNICODE) ?>;
    var destinataire = document.getElementById('contacter-destinataire');
    var fiche = document.getElementById('contacter-fiche');

    // Le destinataire choisi s'affiche en entier : on écrit à une personne, pas
    // à une ligne de menu déroulant — son rôle et sa langue changent le ton du
    // message autant que son adresse.
    function montrerContact() {
        var c = contacts[destinataire.value];
        if (!c) { fiche.textContent = ''; return; }
        var bouts = [c.email];
        if (c.role) bouts.push(c.role);
        if (c.telephone) bouts.push(c.telephone);
        if (c.langue) bouts.push(c.langue);
        if (c.booking) bouts.push('booking');
        if (c.facturation) bouts.push('facturation');
        fiche.textContent = bouts.join(' · ');
    }
    var sujet = document.getElementById('contacter-sujet');
    var corps = document.getElementById('contacter-corps');
    var modeleSel = document.getElementById('contacter-modele');
    // Modèle chargé et texte qu'il a produit : de quoi le RE-résoudre si l'on
    // change de destinataire ensuite, et de quoi savoir si l'on peut le faire
    // sans écraser une phrase écrite à la main (voir appliquerModele()).
    var modeleCharge = null;
    var produit = { sujet: null, corps: null };

    // Les variables sont résolues ICI, pour qu'on relise le message tel qu'il
    // partira, destinataire compris. Le serveur repasse dessus à l'envoi, au cas
    // où (mailing_personnaliser(), lib/booking.php) — même liste de variables.
    function resoudre(texte, contact) {
        return String(texte || '')
            .split('{{prenom}}').join((contact && contact.prenom) || '')
            .split('{{nom_structure}}').join(nomStructure);
    }

    function appliquerModele(modele, forcer) {
        var c = contacts[destinataire.value] || {};
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

    destinataire.addEventListener('change', function () {
        montrerContact();
        // Le « Bonjour {{prenom}} » d'un modèle doit suivre le destinataire :
        // sans ça, changer de personne après avoir chargé le modèle laissait le
        // prénom du précédent dans le message.
        if (modeleCharge) { appliquerModele(modeleCharge, false); }
    });
    montrerContact();

    if (modeleSel) modeleSel.addEventListener('change', function () {
        var m = modeles[this.value];
        if (!m) { modeleCharge = null; return; }
        modeleCharge = m;
        appliquerModele(m, true);
        if (m.expediteur_id) document.getElementById('contacter-expediteur').value = m.expediteur_id;
    });

    // Annuler REND le message tel qu'il était à l'ouverture — c'est-à-dire le
    // brouillon enregistré, ou rien. Se contenter de masquer la fenêtre laissait
    // le texte dans les champs : en la rouvrant on le retrouvait, et on pouvait
    // croire qu'annuler l'avait enregistré. Rien n'est écrit en base tant qu'on
    // n'a pas cliqué « Enregistrer le brouillon ».
    var form = document.getElementById('contacter-form');
    var champs = form.querySelectorAll('input:not([type=hidden]), select, textarea');
    var origine = Array.prototype.map.call(champs, function (c) { return c.value; });
    var fermer = function () {
        champs.forEach(function (c, i) { c.value = origine[i]; });
        modeleCharge = null;
        produit.sujet = null;
        produit.corps = null;
        if (modeleSel) { modeleSel.value = ''; }
        montrerContact();
        modal.setAttribute('hidden', '');
    };
    ouvrir.addEventListener('click', function () {
        modal.removeAttribute('hidden');
        // preventScroll + remise à zéro : sur un écran court, le simple fait de
        // donner le focus à l'objet faisait défiler la fenêtre et emportait son
        // titre hors de vue à l'ouverture.
        document.getElementById('contacter-sujet').focus({ preventScroll: true });
        modal.querySelector('.modal-card').scrollTop = 0;
    });
    document.getElementById('contacter-annuler').addEventListener('click', fermer);
    modal.addEventListener('click', function (e) { if (e.target === modal) fermer(); });
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && !modal.hidden) fermer(); });
    // Entrée dans l'objet : passe au message plutôt que de soumettre. Sans ça,
    // le navigateur activerait le PREMIER bouton d'envoi du formulaire —
    // « Enregistrer le brouillon » — et enregistrerait sans qu'on l'ait demandé.
    document.getElementById('contacter-sujet').addEventListener('keydown', function (e) {
        if (e.key === 'Enter') {
            e.preventDefault();
            document.getElementById('contacter-corps').focus();
        }
    });
})();
</script>
