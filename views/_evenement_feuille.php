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
                    <button type="submit" class="btn ghost btn-sm" title="Pose <?= e(implode(', ', FEUILLE_HORAIRES_TYPES)) ?> — à compléter et à élaguer ensuite."><?= icon('rows-3') ?> Déroulé type</button>
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
                    <summary class="btn ghost btn-sm icon-only" title="Ajouter au déroulé" aria-label="Ajouter au déroulé"><?= icon('plus') ?></summary>
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
        <?php if ($errFeuille !== ''): ?><p class="err"><?= e($errFeuille) ?></p><?php endif; ?>

        <?php if (!$feuilleElements): ?>
            <p class="muted small">Aucun élément pour l'instant.</p>
        <?php else: ?>
        <ul class="feuille-liste">
            <?php foreach ($feuilleElements as $i => $el): ?>
            <?php
                $type = (string) $el['type'];
                $meta = FEUILLE_TYPES[$type] ?? FEUILLE_TYPES['note'];
            ?>
            <li class="feuille-item" id="feuille-<?= (int) $el['id'] ?>">
                <?php // Trois zones : les flèches à gauche, la ligne au milieu, les
                      // actions à droite — dont le crayon, tout au bout, comme
                      // ailleurs dans l'application.
                      //
                      // Le formulaire s'ouvre par une CASE À COCHER cachée plutôt
                      // que par un <details> : le crayon devait passer après la
                      // corbeille, or le déclencheur d'un <details> est son
                      // <summary>, qui précède forcément son contenu — et un
                      // <summary> ne peut pas contenir les formulaires de
                      // déplacement et de suppression. La case, elle, se place où
                      // l'on veut, et :has() fait le reste. ?>
                <?php if ($peutEcrireEv): ?>
                <div class="feuille-fleches">
                    <?php // Les flèches n'apparaissent qu'aux extrémités utiles :
                          // monter le premier ou descendre le dernier ne ferait
                          // rien, et un bouton qui ne fait rien se clique quand même. ?>
                    <?php foreach ([['monter', 'chevron-up', 'Monter', $i > 0],
                                    ['descendre', 'chevron-down', 'Descendre', $i < count($feuilleElements) - 1]] as [$sens, $ico, $titre, $montrer]): ?>
                        <?php if ($montrer): ?>
                        <form method="post" action="?p=evenement_feuille_deplacer" class="d-inline">
                            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                            <input type="hidden" name="id" value="<?= (int) $el['id'] ?>">
                            <input type="hidden" name="sens" value="<?= e($sens) ?>">
                            <button type="submit" class="btn ghost btn-sm icon-only" title="<?= e($titre) ?>" aria-label="<?= e($titre) ?>"><?= icon($ico) ?></button>
                        </form>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>

                <?php // Tout sur UNE ligne tant que ça tient : l'intitulé, puis le
                      // détail à la suite, séparé par des points médians. Une
                      // feuille de route se parcourt du regard — quatre lignes par
                      // élément en faisaient une page à lire. ?>
                <div class="feuille-sommaire">
                    <span class="feuille-ico" title="<?= e($meta['libelle']) ?>"><?= icon($meta['icone']) ?></span>
                    <span class="feuille-corps"><?php require __DIR__ . '/_evenement_feuille_ligne.php'; ?></span>
                </div>

                <div class="feuille-actions">
                    <?php if ($type === 'fichier' && trim((string) $el['fichier']) !== ''): ?>
                    <a class="btn ghost btn-sm icon-only" href="?p=evenement_fichier&id=<?= (int) $el['id'] ?>"
                       title="Télécharger" aria-label="Télécharger la pièce jointe"><?= icon('download') ?></a>
                    <?php endif; ?>
                    <?php if ($peutEcrireEv): ?>
                    <?php // La corbeille n'apparaît qu'une fois la ligne ouverte en
                          // modification, comme partout ailleurs dans l'application :
                          // c'est un geste irréversible, il n'a pas à être à portée
                          // de clic quand on ne fait que lire. La suppression d'une
                          // pièce jointe emporte le fichier avec elle, d'où la
                          // confirmation. ?>
                    <form method="post" action="?p=evenement_feuille_supprimer" class="d-inline feuille-supprimer"
                          data-confirm="<?= $type === 'fichier'
                              ? 'Supprimer cette pièce jointe ? Le fichier sera effacé du serveur.'
                              : 'Supprimer cet élément du déroulé ?' ?>">
                        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                        <input type="hidden" name="id" value="<?= (int) $el['id'] ?>">
                        <button type="submit" class="btn ghost btn-sm icon-only" title="Supprimer" aria-label="Supprimer"><?= icon('trash') ?></button>
                    </form>
                    <?php // Le crayon ouvre le formulaire et le referme : c'est le
                          // même contrôle, qui bascule en croix. ?>
                    <label class="feuille-crayon btn ghost btn-sm icon-only" title="Modifier">
                        <input type="checkbox" class="feuille-bascule" aria-label="Modifier cet élément">
                        <span class="feuille-edit-lbl"><?= icon('pencil') ?></span>
                        <span class="feuille-edit-x"><?= icon('x') ?></span>
                    </label>
                    <?php endif; ?>
                </div>

                <?php if ($peutEcrireEv): ?>
                <form method="post" action="?p=evenement_feuille_modifier" class="form feuille-form feuille-form-ligne">
                    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="id" value="<?= (int) $el['id'] ?>">
                    <?php $fChamps = $meta['champs']; $fEl = $el; $fAide = $meta['aide']; require __DIR__ . '/_evenement_feuille_champs.php'; ?>
                    <div class="form-actions">
                        <button type="submit" class="btn"><?= icon('save') ?> Enregistrer</button>
                    </div>
                </form>
                <?php endif; ?>
            </li>
            <?php endforeach; ?>
        </ul>
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
