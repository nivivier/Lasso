<?php
// Carte « Déroulé » d'un événement : une liste ordonnée d'éléments de types
// différents (horaire, adresse, contact, pièce jointe, note — voir
// FEUILLE_TYPES, lib/feuille_route.php). C'est la section que l'on compose ici ;
// la FEUILLE DE ROUTE, elle, est le document entier — le déroulé plus les
// informations de la date et l'organisation (bouton en haut de la fiche).
//
// Une seule carte, et non quatre : l'ordre court à TRAVERS les types, parce que
// c'est ainsi qu'on lit une feuille de route — dans l'ordre de la journée, pas
// rangée par catégorie. D'où les flèches de déplacement sur chaque ligne plutôt
// qu'un tri automatique : « arrivée 14h » se place avant « hôtel », même si
// l'hôtel n'a pas d'heure.
//
// Rien ici ne demande de JavaScript : le menu d'ajout est un <details>, le
// formulaire de modification d'une ligne s'ouvre par une case à cocher cachée,
// et le formulaire d'ajout est déplié par le SERVEUR selon ?ajout=<type>. Le
// mécanisme générique des cartes éditables (.card-edit-btn, assets/app.js) ne
// convenait pas — il suppose UNE zone d'édition par carte, là où celle-ci en
// compte autant que d'éléments.
//
// Attendu de l'appelant :
//   $feuilleElements (array) les éléments, déjà ordonnés (feuille_elements()).
//   $feuilleContacts (array) les contacts rattachables.
//   $id, $peutEcrireEv, $ok, $errFeuille.
$feuilleElements = $feuilleElements ?? [];
$feuilleContacts = $feuilleContacts ?? [];
// Un déroulé déjà entamé n'a plus besoin qu'on le lui propose en bloc.
$feuilleAHoraire = (bool) array_filter($feuilleElements, fn (array $el): bool => (string) $el['type'] === 'horaire');
// Type d'élément à ajouter, choisi dans le menu « + » : le formulaire est déplié
// par le serveur, il n'y a rien à tenir côté client.
$feuilleAjout = valeur_autorisee($_GET['ajout'] ?? '', array_keys(FEUILLE_TYPES));
// Compte rendu du dernier envoi à l'équipe, passé par l'URL (route
// evenement_feuille_email) : un rechargement ne doit pas renvoyer les messages.
$mailFeuille = trim((string) ($_GET['mailFeuille'] ?? ''));
$mailEchecs = trim((string) ($_GET['mailEchecs'] ?? ''));
$mailSans = trim((string) ($_GET['mailSans'] ?? ''));
?>
<?php // Ni .card-block ni .page-head autour du contenu : la première ramène
      // toute .grid2 à une colonne (elle vise les sous-sections d'une carte
      // étroite), la seconde est un conteneur flex destiné à la seule ligne de
      // titre. Le contenu de la carte est donc posé à plat. ?>
<?php // Exemplaire unique de la liste d'intitulés d'horaire : elle est proposée
      // par chaque champ « Intitulé » d'un horaire (list=), à l'ajout comme à la
      // modification, et n'a besoin d'exister qu'une fois dans la page. ?>
<datalist id="feuille-horaires-types">
    <?php foreach (FEUILLE_HORAIRES_TYPES as $h): ?><option value="<?= e($h) ?>"><?php endforeach; ?>
</datalist>
<div class="card mt-22" id="carte-feuille">
        <?php // Les boutons d'ajout vivent dans la ligne de titre, en haut à
              // droite, comme toutes les actions d'une carte de l'application.
              // Le formulaire qu'ils déplient passe à la ligne sous le titre
              // (.feuille-head est une rangée qui s'enroule) : à leur place, il
              // aurait été comprimé dans la largeur d'un bouton. ?>
        <div class="card-head-row feuille-head">
            <?php // « Déroulé », comme sur la feuille elle-même : la carte en
                  // compose une section, elle n'est pas la feuille entière —
                  // celle-ci porte aussi les informations de la date et
                  // l'organisation. Sans compteur : on ne consulte pas une
                  // feuille de route pour savoir combien elle a de lignes. ?>
            <h2 class="mt-0">Déroulé</h2>
            <?php // Un dépliant par type : le choix du type change les champs, et un
                  // seul formulaire qui se réécrirait demanderait du JavaScript pour
                  // ce que cinq intitulés disent mieux. ?>
            <div class="feuille-ajout">
                <?php // Le déroulé type, tant qu'aucun horaire n'est posé : d'une
                      // date à l'autre ce sont les mêmes cinq moments, et les
                      // retaper chaque fois n'apprend rien à personne. ?>
                <?php if (!$feuilleAHoraire): ?>
                <form method="post" action="?p=evenement_feuille_deroule" class="d-inline">
                    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="evenement_id" value="<?= (int) $id ?>">
                    <button type="submit" class="btn ghost" title="Pose <?= e(implode(', ', FEUILLE_HORAIRES_TYPES)) ?> — à compléter et à élaguer ensuite."><?= icon('rows-3') ?> Déroulé type</button>
                </form>
                <?php endif; ?>
                <?php // Cinq boutons pour cinq types encombraient l'en-tête d'une
                      // carte qui n'en demandait qu'un. Un « + » les déplie en
                      // menu, comme les entonnoirs des colonnes de liste.
                      //
                      // Chaque entrée est un LIEN, pas un déclencheur : la page
                      // revient avec ?ajout=<type> et le formulaire s'ouvre,
                      // déplié par le serveur. Rien à tenir en JavaScript, et
                      // l'adresse dit ce qui est ouvert. ?>
                <details class="feuille-menu">
                    <summary class="btn ghost icon-only" title="Ajouter au déroulé" aria-label="Ajouter au déroulé"><?= icon('plus') ?></summary>
                    <div class="feuille-menu-panneau">
                        <?php foreach (FEUILLE_TYPES as $cle => $meta): ?>
                        <a href="?p=evenement&id=<?= (int) $id ?>&ajout=<?= e($cle) ?>#carte-feuille"
                           title="<?= e($meta['aide']) ?>"><?= icon($meta['icone']) ?> <?= e($meta['libelle']) ?></a>
                        <?php endforeach; ?>
                    </div>
                </details>
            </div>
        </div>

        <?php if ($ok === 'feuille'): ?><p class="ok flash">Déroulé enregistré.</p><?php endif; ?>
        <?php // Compte rendu de l'envoi à l'équipe. Les trois codes d'échec disent
              // ce qui manque plutôt que « échec » : sans adresse d'expédition,
              // sans employé lié, ou sans aucune adresse chez eux — trois
              // situations, trois corrections différentes. ?>
        <?php if ($errFeuille !== ''): ?>
        <p class="err"><?= e(match ($errFeuille) {
            'no_exp'    => "Aucune adresse d'expédition n'est configurée (Paramètres → E-mails).",
            'no_dest'   => "Aucun employé lié à cette date n'a d'adresse e-mail.",
            'no_employe' => "Aucun employé n'est lié à cette date : liez-les dans la carte « Employés ».",
            'type'      => "Type d'élément inconnu.",
            default     => $errFeuille,
        }) ?></p>
        <?php endif; ?>
        <?php if ($mailFeuille !== ''): ?>
        <?php [$mfEnvoyes, $mfTotal] = array_pad(explode('/', $mailFeuille, 2), 2, '0'); ?>
        <p class="<?= (int) $mfEnvoyes === (int) $mfTotal ? 'ok' : 'warn' ?>">
            Feuille de route envoyée à <?= (int) $mfEnvoyes ?> employé(e)s sur <?= (int) $mfTotal ?>.
            <?php if ($mailEchecs !== ''): ?><br>Échec pour : <?= e($mailEchecs) ?>.<?php endif; ?>
            <?php if ($mailSans !== ''): ?><br><span class="muted">Sans adresse e-mail, donc non destinataires : <?= e($mailSans) ?>.</span><?php endif; ?>
        </p>
        <?php endif; ?>

        <?php if (!$feuilleElements): ?>
            <p class="muted small">Aucun élément pour l'instant.</p>
        <?php else: ?>
        <ul class="feuille-liste">
            <?php foreach ($feuilleElements as $i => $el): ?>
            <?php
                $type = (string) $el['type'];
                $meta = FEUILLE_TYPES[$type] ?? FEUILLE_TYPES['note'];
            ?>
            <li class="feuille-item plan-row" id="feuille-<?= (int) $el['id'] ?>" data-id="<?= (int) $el['id'] ?>">
                <?php // Même motif que les autres listes ordonnables de
                      // l'application (?p=postes, ?p=compta_plan — voir
                      // docs/UI.md §2d et §4) : poignée à gauche, lecture et
                      // formulaire tous deux dans le document, crayon en tête de
                      // la colonne d'actions.
                      //
                      // .plan-nom et .plan-edit-btn sont masqués par défaut et
                      // révélés par .dnd-on, que le script pose sur la liste :
                      // SANS JavaScript, c'est le formulaire qui s'affiche, et
                      // l'on édite directement. Rien à basculer. ?>
                <?php if ($peutEcrireEv): ?>
                <span class="plan-grip" draggable="true" title="Glisser pour ranger ailleurs" aria-hidden="true"><?= icon('grip') ?></span>
                <?php endif; ?>

                <div class="feuille-sommaire">
                    <span class="feuille-ico" title="<?= e($meta['libelle']) ?>"><?= icon($meta['icone']) ?></span>
                    <span class="feuille-corps <?= $peutEcrireEv ? 'plan-nom' : '' ?>"><?php require __DIR__ . '/_evenement_feuille_ligne.php'; ?></span>
                    <?php if ($peutEcrireEv): ?>
                    <?php // Les boutons de l'édition vivent dans la colonne d'actions,
                          // à la place du crayon — pas au pied du formulaire. ?>
                    <form method="post" action="?p=evenement_feuille_modifier" class="form feuille-form plan-edit" id="plan-edit-<?= (int) $el['id'] ?>">
                        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                        <input type="hidden" name="id" value="<?= (int) $el['id'] ?>">
                        <?php $fChamps = $meta['champs']; $fEl = $el; $fAide = $meta['aide']; require __DIR__ . '/_evenement_feuille_champs.php'; ?>
                    </form>
                    <?php endif; ?>
                </div>

                <div class="feuille-actions">
                    <?php if ($peutEcrireEv): ?>
                    <?php // Repli sans JavaScript : les flèches, masquées dès que
                          // le glisser-déposer est actif (.dnd-on .plan-fallback).
                          // Empêchées aux extrémités plutôt que retirées — la
                          // colonne garde sa largeur d'une ligne à l'autre. ?>
                    <form method="post" action="?p=evenement_feuille_deplacer" class="d-inline plan-fallback">
                        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                        <input type="hidden" name="id" value="<?= (int) $el['id'] ?>">
                        <button type="submit" name="sens" value="monter" class="btn ghost btn-sm icon-only" title="Monter" aria-label="Monter" <?= $i === 0 ? 'disabled' : '' ?>><?= icon('chevron-up') ?></button>
                        <button type="submit" name="sens" value="descendre" class="btn ghost btn-sm icon-only" title="Descendre" aria-label="Descendre" <?= $i === count($feuilleElements) - 1 ? 'disabled' : '' ?>><?= icon('chevron-down') ?></button>
                    </form>
                    <?php if ($type === 'fichier' && trim((string) $el['fichier']) !== ''): ?>
                    <a class="btn ghost btn-sm icon-only" href="?p=evenement_fichier&id=<?= (int) $el['id'] ?>"
                       title="Télécharger" aria-label="Télécharger la pièce jointe"><?= icon('download') ?></a>
                    <?php endif; ?>
                    <?php // En édition, le crayon cède la place au trio : enregistrer
                          // (mis en évidence), supprimer (rouge) et annuler. La croix se
                          // pose exactement là où était le crayon, tout à droite ;
                          // enregistrer et supprimer se rangent avant elle. Le bouton
                          // d'enregistrement est rattaché au formulaire de la ligne par
                          // form=, puisqu'il vit hors de lui. ?>
                    <button type="submit" form="plan-edit-<?= (int) $el['id'] ?>" class="btn btn-sm cell-edition" title="Enregistrer"><?= icon('save') ?> Enregistrer</button>
                    <?php // La suppression d'une pièce jointe emporte le fichier :
                          // elle se confirme, contrairement au retrait d'un horaire. ?>
                    <form method="post" action="?p=evenement_feuille_supprimer" class="d-inline plan-supprimer"
                          data-confirm="<?= $type === 'fichier'
                              ? 'Supprimer cette pièce jointe ? Le fichier sera effacé du serveur.'
                              : 'Supprimer cet élément du déroulé ?' ?>">
                        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                        <input type="hidden" name="id" value="<?= (int) $el['id'] ?>">
                        <button type="submit" class="btn danger btn-sm icon-only" title="Supprimer" aria-label="Supprimer"><?= icon('trash') ?></button>
                    </form>
                    <button type="button" class="btn ghost btn-sm icon-only plan-edit-btn" title="Modifier" aria-label="Modifier"><?= icon('pencil') ?></button>
                    <button type="button" class="btn ghost btn-sm icon-only plan-annuler-btn cell-edition" title="Annuler" aria-label="Annuler"><?= icon('x') ?></button>
                    <?php endif; ?>
                </div>
            </li>
            <?php endforeach; ?>
        </ul>
        <?php endif; ?>

        <?php // Exemplaire unique du formulaire de repositionnement : le script y
              // écrit l'ordre complet au dépôt et l'envoie (lassoOrdreListe(),
              // assets/app.js). Le serveur renumérote. ?>
        <?php if ($peutEcrireEv): ?>
        <form method="post" action="?p=evenement_feuille_ordre" id="reorder-form" hidden>
            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="evenement_id" value="<?= (int) $id ?>">
            <input type="hidden" name="id" value="">
            <input type="hidden" name="order" value="">
        </form>
        <?php endif; ?>

        <?php // Le formulaire du type choisi dans le menu « + », déplié par le
              // serveur : il a besoin de toute la largeur de la carte, ce qu'un
              // panneau de menu ne peut pas offrir. ?>
        <?php if ($peutEcrireEv && $feuilleAjout !== ''): ?>
        <?php $meta = FEUILLE_TYPES[$feuilleAjout]; ?>
        <form method="post" action="?p=evenement_feuille_ajouter" class="form feuille-form feuille-form-ajout"<?= $feuilleAjout === 'fichier' ? ' enctype="multipart/form-data"' : '' ?>>
            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="evenement_id" value="<?= (int) $id ?>">
            <input type="hidden" name="type" value="<?= e($feuilleAjout) ?>">
            <?php if ($feuilleAjout === 'fichier'): ?>
            <label class="fr-champ fr-fichier">Fichier <span class="muted fr-precision">(PDF, image ou bureautique, 8 Mo)</span>
                <input type="file" name="fichier" required></label>
            <?php endif; ?>
            <?php $fChamps = $meta['champs']; $fEl = []; $fAide = $meta['aide']; require __DIR__ . '/_evenement_feuille_champs.php'; ?>
            <div class="form-actions">
                <a class="btn ghost btn-sm icon-only" href="?p=evenement&id=<?= (int) $id ?>#carte-feuille" title="Annuler" aria-label="Annuler"><?= icon('x') ?></a>
                <button type="submit" class="btn"><?= icon('plus') ?> Ajouter</button>
            </div>
        </form>
        <?php endif; ?>
</div>
<?php if ($peutEcrireEv && $feuilleElements): ?>
<?php // Liste plate : lassoOrdreListe(), le même appel que ?p=postes. C'est lui
      // qui pose .dnd-on — donc qui fait passer la carte du repli (formulaires
      // ouverts, flèches) au mode glisser-déposer. ?>
<script nonce="<?= e(csp_nonce()) ?>">
lassoOrdreListe({
    containerSelector: '#carte-feuille',
    rowsSelector: '.feuille-item',
    scrollKey: 'feuilleScroll',
    formAction: '?p=evenement_feuille_ordre',
});
</script>
<?php endif; ?>
