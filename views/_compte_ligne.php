<?php
// Les deux lignes d'un compte dans le tableau de ?p=utilisateurs : celle qu'on lit,
// et celle qui s'ouvre au crayon. Un seul gabarit, rendu par la boucle de la
// page ET par route_utilisateurs() quand un compte vient d'être créé sans recharger
// (docs/UI.md § 5) — deux exemplaires divergeraient à la première retouche.
//
// ⚠️ Les droits ne sont PLUS une colonne par module. À onze modules, la matrice
// demandait onze colonnes de 70px : les en-têtes se chevauchaient dès qu'on
// n'était pas en plein écran, pour deux comptes à régler deux fois l'an. La
// liste ne porte plus qu'un résumé, et les droits se règlent dans le panneau
// d'édition, un module par ligne, avec son nom en toutes lettres — ce qui tient
// à toute largeur et ne bougera pas au douzième module.
//
// Attendu de l'appelant : $c (le compte), $niveaux (ses droits), $moi, et
// $personnes (l'annuaire, pour le rattachement).
/** @var array $c */ /** @var array $niveaux */ /** @var int $moi */ /** @var array $personnes */
$estMoi = (int) $c['id'] === $moi;
$fid    = 'compte-form-' . (int) $c['id'];
// La personne de l'annuaire que ce compte désigne, s'il en désigne une.
$personneId = (int) ($c['personne_id'] ?? 0);
$personneNom = '';
foreach ($personnes as $pe) { if ($pe['id'] === $personneId) { $personneNom = $pe['libelle']; } }
// Nom affiché : celui de la personne rattachée s'il y en a une, sinon
// l'identité propre du compte, sinon son adresse (utilisateur_nom()).
$nomAffiche = utilisateur_nom($c);
$niveauIcone = ['' => 'eye-off', 'lecture' => 'eye', 'ecriture' => 'pencil'];
$niveauTexte = ['' => 'Aucun accès', 'lecture' => 'Lecture', 'ecriture' => 'Écriture'];
$libelleModule = fn (string $m): string => $m === 'coeur' ? MODULE_COEUR['label'] : MODULES[$m]['label'];
$descModule = function (string $m): string {
    $d = $m === 'coeur' ? MODULE_COEUR['description'] : MODULES[$m]['description'];
    return mb_strlen($d, 'UTF-8') > 96 ? mb_substr($d, 0, 95, 'UTF-8') . '…' : $d;
};
// Le résumé de la ligne de lecture : ce qu'on veut savoir d'un coup d'œil, à
// savoir « est-ce un administrateur ? » puis « sur combien de modules ? ».
// Le détail est à un clic, et onze icônes alignées ne le disaient pas mieux.
$estAdmin = ($niveaux['coeur'] ?? null) === 'ecriture';
$modulesOuverts = [];
foreach (PERMISSION_MODULES as $m) {
    if (($niveaux[$m] ?? '') !== '') { $modulesOuverts[] = $libelleModule($m); }
}
$derniere = trim((string) ($c['derniere_connexion_le'] ?? ''));
$derniere = $derniere === '' ? '' : date('d.m.Y à H:i', strtotime($derniere));
?>
            <tr class="compte-row">
                <td>
                    <div class="compte-identite">
                        <span class="compte-nom"><?= e($nomAffiche) ?></span>
                        <?php if ($estMoi): ?><span class="badge muted-badge">vous</span><?php endif; ?>
                    </div>
                    <?php if ($nomAffiche !== (string) $c['email']): ?>
                        <div class="muted small"><?= e($c['email']) ?></div>
                    <?php endif; ?>
                    <?php if ($personneNom !== ''): ?>
                        <div class="muted small"><?= icon('user') ?>
                            <a href="?p=personne&amp;id=<?= $personneId ?>"><?= e($personneNom) ?></a></div>
                    <?php endif; ?>
                </td>
                <td data-label="Droits">
                    <?php if ($estAdmin): ?>
                        <span class="badge ok-badge">Administration</span>
                    <?php elseif (!$modulesOuverts): ?>
                        <span class="muted small">aucun accès</span>
                    <?php else: ?>
                        <span class="muted small" title="<?= e(implode(', ', $modulesOuverts)) ?>">
                            <?= count($modulesOuverts) ?> module<?= count($modulesOuverts) > 1 ? 's' : '' ?>
                        </span>
                    <?php endif; ?>
                </td>
                <td class="muted small nowrap" data-label="Dernière connexion"><?= $derniere !== '' ? e($derniere) : '<span class="muted">jamais</span>' ?></td>
                <td class="muted small nowrap" data-label="Créé le"><?= e(date('d.m.Y', strtotime((string) $c['cree_le']))) ?></td>
                <td class="actions nowrap">
                    <?php // Le crayon seul : la corbeille ne paraît qu'en édition,
                          // comme partout ailleurs (docs/UI.md § 3). ?>
                    <button type="button" class="btn ghost btn-sm icon-only compte-edit-btn" title="Modifier" aria-label="Modifier le compte"><?= icon('pencil') ?></button>
                </td>
            </tr>
            <?php // Édition : une ligne pleine largeur, ouverte par le crayon. Rien
                  // n'est modifiable tant qu'elle est fermée.
                  //
                  // Le <form> vit en tête de la cellule et les champs s'y
                  // rattachent par form= : c'est ce qui permet au bouton
                  // « Supprimer », qui a le sien, de vivre à côté sans s'y
                  // imbriquer — deux <form> ne s'emboîtent pas. ?>
            <tr class="compte-edit-row" hidden>
                <td colspan="5" class="compte-edit-cell">
                    <form method="post" action="?p=utilisateur_enregistrer" id="<?= e($fid) ?>" class="compte-form" autocomplete="off">
                        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                        <input type="hidden" name="id" value="<?= (int) $c['id'] ?>">
                    </form>

                    <?php // Enregistrer et annuler en haut à droite, comme tout
                          // cadre qui s'ouvre à la place d'une ligne de liste
                          // (docs/UI.md § 2c) : les commandes restent là où
                          // était la ligne, et la croix à l'emplacement même du
                          // crayon qui vient de l'ouvrir. Le panneau fait deux
                          // écrans de haut une fois les onze modules dépliés —
                          // au pied, « Enregistrer » se cherchait. ?>
                    <div class="cadre-edit-head">
                        <span class="cadre-edit-titre"><?= e($nomAffiche) ?></span>
                        <div class="cadre-edit-actions">
                            <button type="submit" form="<?= $fid ?>" class="btn btn-sm icon-only" title="Enregistrer" aria-label="Enregistrer"><?= icon('save') ?></button>
                            <button type="button" class="btn ghost btn-sm icon-only compte-cancel-btn" title="Annuler" aria-label="Annuler"><?= icon('x') ?></button>
                        </div>
                    </div>

                    <div class="compte-edit-identite">
                        <?php // Rattaché à une personne, le compte n'a plus d'identité
                              // à lui : elle vient de l'annuaire, et deux champs de
                              // plus ici, c'était deux noms qui pouvaient diverger. ?>
                        <?php if ($personneId === 0): ?>
                        <label>Prénom <input form="<?= $fid ?>" name="prenom" value="<?= e((string) ($c['prenom'] ?? '')) ?>" autocomplete="off"></label>
                        <label>Nom <input form="<?= $fid ?>" name="nom" value="<?= e((string) ($c['nom'] ?? '')) ?>" autocomplete="off"></label>
                        <?php endif; ?>
                        <label>E-mail <input form="<?= $fid ?>" name="email" type="email" value="<?= e((string) $c['email']) ?>" required autocomplete="off"></label>
                        <label>Nouveau mot de passe
                            <input form="<?= $fid ?>" type="password" name="nouveau_mot_de_passe" autocomplete="new-password"
                                   minlength="<?= PASSWORD_MIN ?>" placeholder="laisser vide = inchangé">
                        </label>
                        <?php // Le compte et la personne de l'annuaire : c'est ici
                              // qu'on dit qu'ils sont le même être humain
                              // (docs/SPEC_PERSONNES.md). Champ cherchable, comme le
                              // bénéficiaire d'une pièce — l'annuaire compte trois
                              // mille fiches, un menu déroulant ne s'y parcourt pas. ?>
                        <div class="compte-edit-personne">
                            <span class="lbl-champ">Personne de l'annuaire</span>
                            <div class="cat-search" data-cat-search data-hydrater data-vider-en-saisie>
                                <input type="text" class="cat-search-input" autocomplete="off" placeholder="Chercher un nom…"
                                       aria-label="Rattacher ce compte à une personne">
                                <input form="<?= $fid ?>" type="hidden" name="personne_id" class="cat-search-val"
                                       value="<?= $personneId ?: '' ?>">
                                <ul class="cat-search-list" hidden role="listbox">
                                    <?php foreach ($personnes as $pe): ?>
                                    <li data-val="<?= (int) $pe['id'] ?>"><?= e($pe['libelle']) ?></li>
                                    <?php endforeach; ?>
                                </ul>
                            </div>
                        </div>
                    </div>

                    <div class="section-head mt-22">
                        <h3 class="sub mt-0">Droits</h3>
                        <?php // Le raccourci : onze modules à mettre au même niveau,
                              // c'est onze clics pour un geste qui n'en vaut qu'un —
                              // ouvrir un compte en lecture seule, ou tout rendre à
                              // un administrateur. Il ne fait que poser les onze
                              // boutons ; rien n'est enregistré avant « Enregistrer ». ?>
                        <div class="perm-tous">
                            <span class="muted small">Tout mettre sur</span>
                            <?php foreach (['', 'lecture', 'ecriture'] as $niv): ?>
                            <button type="button" class="btn ghost btn-sm perm-tout" data-val="<?= e($niv) ?>"><?= icon($niveauIcone[$niv]) ?> <?= e($niveauTexte[$niv]) ?></button>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <table class="list perm-liste">
                        <tbody>
                        <?php foreach (PERMISSION_MODULES as $m): $val = $niveaux[$m] ?? ''; $lib = $libelleModule($m); ?>
                        <tr>
                            <td>
                                <strong><?= e($lib) ?></strong>
                                <div class="muted small"><?= e($descModule($m)) ?></div>
                            </td>
                            <td class="actions">
                                <?php // data-initial : le niveau que le SERVEUR a
                                      // envoyé, pour qu'« Annuler » puisse le
                                      // rendre. Un champ caché ne sait pas le
                                      // faire — son attribut value et sa
                                      // propriété sont la même chose, donc
                                      // form.reset() n'a rien à restaurer (ce
                                      // n'est vrai que de type="hidden" ; les
                                      // champs texte à côté se remettent bien
                                      // tout seuls). ?>
                                <div class="perm-toggle" role="group" data-initial="<?= e($val) ?>"
                                     aria-label="<?= e($lib . ' — ' . $c['email']) ?>">
                                    <?php foreach (['', 'lecture', 'ecriture'] as $niv): ?>
                                    <button type="button" class="perm-btn <?= $val === $niv ? 'on' : '' ?>" data-val="<?= e($niv) ?>"
                                            title="<?= e($niveauTexte[$niv] . ' (' . $lib . ')') ?>"><?= icon($niveauIcone[$niv]) ?>
                                        <span class="perm-btn-txt"><?= e($niveauTexte[$niv]) ?></span></button>
                                    <?php endforeach; ?>
                                    <input form="<?= $fid ?>" type="hidden" name="niveaux[<?= e($m) ?>]" value="<?= e($val) ?>">
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>

                    <?php // Supprimer vit au PIED du cadre, à distance des deux
                          // gestes courants (docs/UI.md § 2c). Supprimer son
                          // propre compte n'est pas proposé :
                          // route_utilisateur_supprimer() le refuserait. ?>
                    <?php if (!$estMoi): ?>
                    <div class="cadre-edit-pied">
                        <form method="post" action="?p=utilisateur_supprimer"
                              data-confirm="Supprimer définitivement le compte <?= e($c['email']) ?> ?">
                            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                            <input type="hidden" name="id" value="<?= (int) $c['id'] ?>">
                            <button type="submit" class="btn danger btn-sm"><?= icon('trash') ?> Supprimer le compte</button>
                        </form>
                    </div>
                    <?php endif; ?>
                </td>
            </tr>
