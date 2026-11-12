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
//   $contacterCibles  [id structure => ['nom', 'statut', 'contacts' (joignables), 'brouillon']]
//   $expediteurs, $modelesMessage, $projetLabels, $campagneProjets
//   $contacterRetourCampagne (facultatif) la campagne où revenir après l'envoi
// et, pour ouvrir : un élément portant data-contacter="<id structure>".
$contacterRetourCampagne = (int) ($contacterRetourCampagne ?? 0);
?>
<div id="contacter-modal" class="modal-overlay" hidden>
    <div class="modal-card modal-contacter">
        <form method="post" action="?p=structure_message_envoyer<?= $depuisQs ?? '' ?>" class="form" id="contacter-form">
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
                      // du déroulé d'une date (menu_deroulant_html()) — il se referme
                      // au clic hors de lui, comme les autres. ?>
                <?php // .btn-compact : sur téléphone, le libellé reste écrit mais
                      // en plus petit et sur deux lignes, comme « Réinstaller la
                      // dernière version » de ?p=maj. Le garder entier compte ici :
                      // une icône de document ne dit pas qu'elle CHARGE un modèle,
                      // et le geste n'est pas rattrapable d'un coup d'œil. ?>
                <?= menu_deroulant_html(
                    ['icone' => 'file-text', 'libelle' => "Charger un\nmodèle", 'classe' => 'btn-compact'],
                    array_map(fn ($m) => [
                        'libelle' => (string) $m['nom'],
                        'attrs'   => ['data-modele' => (int) $m['id']],
                    ], $modelesMessage),
                    ['classe' => 'contacter-modele', 'id' => 'contacter-modele']
                ) ?>
                <?= bouton_fermer_modal_html('contacter-fermer') ?>
            </div>

            <?php // Une structure « À vérifier » reste contactable — c'est le sens
                  // du statut : on lui écrit, mais on doute de ce qu'on a d'elle.
                  // La mise en garde se lit AVANT les champs, pleine largeur et
                  // hors de la grille à deux colonnes, sinon elle se rangerait à
                  // côté de l'expéditeur comme si elle le concernait. Ambre :
                  // elle appelle un geste (docs/UI.md § 13). ?>
            <p class="warn" id="contacter-avertissement" hidden></p>

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
                    <?php // Les copies restent repliées : écrire à une structure,
                          // c'est écrire à UNE personne neuf fois sur dix, et deux
                          // champs vides de plus à chaque ouverture diraient le
                          // contraire. Le lien les appelle quand il les faut. ?>
                    <button type="button" class="lien-bouton contacter-copies-lien" data-show="contacter-copies">Ajouter des copies (Cc, Cci)</button>
                </div>
            </div>

            <div class="grid2-optional" id="contacter-copies" hidden>
                <label>Cc <span class="muted small">— lues par le destinataire</span>
                    <input name="cc" id="contacter-cc" placeholder="adresse@exemple.com, autre@exemple.com">
                </label>
                <label>Cci <span class="muted small">— invisibles pour lui</span>
                    <input name="cci" id="contacter-cci" placeholder="adresse@exemple.com">
                </label>
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
            <?php if (!empty($projetLabels)): ?>
            <div class="field-group"><span>Noter dans l'historique du projet</span>
                <span id="contacter-projets"><?= choix_coches_html('projet_ids', $projetLabels, $campagneProjets, 'Aucun') ?></span>
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
<?php
// Ce que assets/structure_contacter.js doit savoir du serveur : les
// structures qu'on peut contacter d'ici, leurs contacts et brouillons, les
// modèles de message.
$structureContacterDonnees = [
    'cibles' => array_map(fn (array $c): array => [
        'nom' => (string) $c['nom'],
        'statut' => (string) ($c['statut'] ?? ''),
        'brouillon' => $c['brouillon'] ? [
            'contact_id' => (int) ($c['brouillon']['contact_id'] ?? 0),
            'expediteur_id' => (int) ($c['brouillon']['expediteur_id'] ?? 0),
            'sujet' => (string) ($c['brouillon']['sujet'] ?? ''),
            'corps' => (string) ($c['brouillon']['corps'] ?? ''),
            'cc'    => (string) ($c['brouillon']['cc'] ?? ''),
            'cci'   => (string) ($c['brouillon']['cci'] ?? ''),
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
    ], $contacterCibles),
    'modeles' => (object) array_column($modelesMessage, null, 'id'),
    'projetsDefaut' => array_map('intval', $campagneProjets),
    'statutAVerifier' => STRUCTURE_STATUT_A_VERIFIER,
    'statutAVerifierLibelle' => structure_statut_libelle(STRUCTURE_STATUT_A_VERIFIER),
];
?>
<script type="application/json" id="structure-contacter-donnees"><?= json_encode($structureContacterDonnees, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG) ?></script>
<script src="<?= e(asset_url('assets/structure_contacter.js')) ?>"></script>
