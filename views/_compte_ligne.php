<?php
// Les deux lignes d'un compte dans le tableau de ?p=comptes : celle qu'on lit,
// et celle qui s'ouvre au crayon. Un seul gabarit, rendu par la boucle de la
// page ET par route_comptes() quand un compte vient d'être créé sans recharger
// (docs/UI.md § 5) — deux exemplaires divergeraient à la première retouche.
//
// Le <form> d'édition vit dans la PREMIÈRE cellule de la ligne d'édition, et
// non hors du tableau : c'est ce qui permet d'insérer un compte entier d'un
// seul fragment. Les champs des autres colonnes s'y rattachent par form=, ce
// qui les laisse chacun dans LEUR colonne.
//
// Attendu de l'appelant : $c (le compte), $niveaux (ses droits), $moi.
/** @var array $c */ /** @var array $niveaux */ /** @var int $moi */
$estMoi = (int) $c['id'] === $moi;
$fid    = 'compte-form-' . (int) $c['id'];
// Nom affiché : l'identité si elle est renseignée, sinon l'adresse — un compte
// créé sans prénom ni nom ne doit pas se présenter comme une ligne vide.
$nomAffiche = trim(trim((string) ($c['prenom'] ?? '')) . ' ' . trim((string) ($c['nom'] ?? '')));
$nomAffiche = $nomAffiche !== '' ? $nomAffiche : (string) $c['email'];
// Droits en lecture : une seule icône par module, celle du niveau accordé.
// Rien de cliquable — la ligne de lecture se lit, elle ne se modifie pas.
$niveauIcone = ['' => 'eye-off', 'lecture' => 'eye', 'ecriture' => 'pencil'];
$niveauTexte = ['' => 'aucun accès', 'lecture' => 'lecture', 'ecriture' => 'écriture'];
$derniere = trim((string) ($c['derniere_connexion_le'] ?? ''));
$derniere = $derniere === '' ? '' : date('d.m.Y à H:i', strtotime($derniere));
?>
            <tr class="compte-row">
                <td>
                    <div class="compte-identite">
                        <span class="compte-nom"><?= e($nomAffiche) ?></span>
                        <?php if ($estMoi): ?><span class="badge muted-badge">vous</span><?php endif; ?>
                        <?php if (($niveaux['coeur'] ?? null) === 'ecriture'): ?><span class="badge ok-badge">admin</span><?php endif; ?>
                    </div>
                    <?php if ($nomAffiche !== (string) $c['email']): ?>
                        <div class="muted small"><?= e($c['email']) ?></div>
                    <?php endif; ?>
                </td>
                <?php foreach (PERMISSION_MODULES as $m): $val = $niveaux[$m] ?? ''; $lib = $m === 'coeur' ? MODULE_COEUR['label'] : MODULES[$m]['label']; ?>
                <td class="perm-col" data-label="<?= e($lib) ?>">
                    <span class="perm-lecture perm-<?= $val === '' ? 'aucun' : ($val === 'lecture' ? 'lecture-niv' : 'ecriture') ?>"
                          title="<?= e($lib . ' — ' . $niveauTexte[$val]) ?>"><?= icon($niveauIcone[$val]) ?></span>
                </td>
                <?php endforeach; ?>
                <td class="muted small nowrap" data-label="Dernière connexion"><?= $derniere !== '' ? e($derniere) : '<span class="muted">jamais</span>' ?></td>
                <td class="muted small nowrap" data-label="Créé le"><?= e(date('d.m.Y', strtotime((string) $c['cree_le']))) ?></td>
                <td class="actions nowrap">
                    <?php // Le crayon seul : la corbeille ne paraît qu'en édition,
                          // comme partout ailleurs (docs/UI.md § 3). ?>
                    <button type="button" class="btn ghost btn-sm icon-only compte-edit-btn" title="Modifier" aria-label="Modifier le compte"><?= icon('pencil') ?></button>
                </td>
            </tr>
            <?php // Édition : une ligne pleine largeur, ouverte par le crayon. Rien
                  // n'est modifiable tant qu'elle est fermée. ?>
            <tr class="compte-edit-row" hidden>
                <td class="compte-edit-identite">
                    <form method="post" action="?p=compte_modifier" id="<?= e($fid) ?>" autocomplete="off">
                        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                        <input type="hidden" name="id" value="<?= (int) $c['id'] ?>">
                    </form>
                    <label>Prénom <input form="<?= $fid ?>" name="prenom" value="<?= e((string) ($c['prenom'] ?? '')) ?>" autocomplete="off"></label>
                    <label>Nom <input form="<?= $fid ?>" name="nom" value="<?= e((string) ($c['nom'] ?? '')) ?>" autocomplete="off"></label>
                    <label>E-mail <input form="<?= $fid ?>" name="email" type="email" value="<?= e((string) $c['email']) ?>" required autocomplete="off"></label>
                    <p class="muted small mb-0">Changer l'adresse invalide les liens de réinitialisation en attente.</p>
                    <label>Nouveau mot de passe
                        <input form="<?= $fid ?>" type="password" name="nouveau_mot_de_passe" autocomplete="new-password"
                               minlength="<?= PASSWORD_MIN ?>" placeholder="laisser vide = inchangé">
                    </label>
                </td>
                <?php foreach (PERMISSION_MODULES as $m): $val = $niveaux[$m] ?? ''; $lib = $m === 'coeur' ? MODULE_COEUR['label'] : MODULES[$m]['label']; ?>
                <td class="perm-col" data-label="<?= e($lib) ?>">
                    <div class="perm-toggle" role="group" aria-label="<?= e($lib . ' — ' . $c['email']) ?>">
                        <button type="button" class="perm-btn <?= $val === '' ? 'on' : '' ?>" data-val="" title="Aucun accès (<?= e($lib) ?>)" aria-label="Aucun accès (<?= e($lib) ?>)"><?= icon('eye-off') ?></button>
                        <button type="button" class="perm-btn <?= $val === 'lecture' ? 'on' : '' ?>" data-val="lecture" title="Lecture (<?= e($lib) ?>)" aria-label="Lecture (<?= e($lib) ?>)"><?= icon('eye') ?></button>
                        <button type="button" class="perm-btn <?= $val === 'ecriture' ? 'on' : '' ?>" data-val="ecriture" title="Écriture (<?= e($lib) ?>)" aria-label="Écriture (<?= e($lib) ?>)"><?= icon('pencil') ?></button>
                        <input form="<?= $fid ?>" type="hidden" name="niveaux[<?= e($m) ?>]" value="<?= e($val) ?>">
                    </div>
                </td>
                <?php endforeach; ?>
                <?php // Enregistrer et Annuler à l'extrême droite de la ligne, sous le
                      // crayon qui a ouvert l'édition. ?>
                <td colspan="3" class="compte-edit-actions">
                    <?php // Enregistrer, supprimer, annuler — l'ordre du geste
                          // (docs/UI.md § 2). Supprimer son propre compte n'est pas
                          // proposé : route_compte_delete() le refuserait. ?>
                    <button type="submit" form="<?= $fid ?>" class="btn btn-sm"><?= icon('save') ?> Enregistrer</button>
                    <?php if (!$estMoi): ?>
                    <form method="post" action="?p=compte_delete" class="d-inline"
                          data-confirm="Supprimer définitivement le compte <?= e($c['email']) ?> ?">
                        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                        <input type="hidden" name="id" value="<?= (int) $c['id'] ?>">
                        <button type="submit" class="btn danger btn-sm icon-only" title="Supprimer le compte" aria-label="Supprimer le compte"><?= icon('trash') ?></button>
                    </form>
                    <?php endif; ?>
                    <button type="button" class="btn ghost btn-sm icon-only compte-cancel-btn" title="Annuler" aria-label="Annuler"><?= icon('x') ?></button>
                </td>
            </tr>
