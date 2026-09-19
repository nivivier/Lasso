<?php
// UNE ligne du déroulé d'un événement (carte « Infos supplémentaires »).
//
// Sorti de la boucle pour être rendu AUSSI tout seul : quand on ajoute un
// élément, la route répond avec cette ligne-là et le script l'insère dans la
// liste, sans recharger la page (route_evenement_feuille_ajouter()). Deux
// exemplaires du même balisage divergeraient à la première retouche.
//
// Attendu de l'appelant :
//   $el            (array) l'élément (feuille_element()).
//   $i             (int)   son rang dans la liste — décide des flèches empêchées.
//   $peutEcrireEv  (bool)  droit d'écriture sur le module événements.
//   $feuilleTotal  (int)   nombre d'éléments, pour la flèche « descendre ».
$type = (string) $el['type'];
$meta = FEUILLE_TYPES[$type] ?? FEUILLE_TYPES['note'];
$feuilleTotal = $feuilleTotal ?? 0;
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
                        <button type="submit" name="sens" value="descendre" class="btn ghost btn-sm icon-only" title="Descendre" aria-label="Descendre" <?= $i === $feuilleTotal - 1 ? 'disabled' : '' ?>><?= icon('chevron-down') ?></button>
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
                    <button type="submit" form="plan-edit-<?= (int) $el['id'] ?>" class="btn btn-sm cell-edition" title="Enregistrer"><?= icon('save') ?><span class="lbl"> Enregistrer</span></button>
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
