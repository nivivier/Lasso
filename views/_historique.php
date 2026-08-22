<?php
// Rend une liste d'entrées d'historique typé. L'appelant définit $histoEntrees
// (nom distinctif pour ne pas heurter d'autres variables de la vue via
// extract()) — chaque entrée : id, type, contenu, cree_le, u_prenom, u_nom.
// Icône + libellé par type via HISTORIQUE_TYPES (lib/booking.php).
//
// $histoModifiable (facultatif) : rend chaque entrée saisie à la main
// modifiable sur place — contenu, date, et bascule note ↔ prise de contact.
// Réservé aux types de HISTORIQUE_TYPES_MODIFIABLES (lib/routes_booking.php) :
// les entrées « Modification » sont produites automatiquement et valent journal
// d'audit. $histoStructureId : la fiche d'où l'on édite, pour y revenir après
// l'enregistrement — ce n'est pas forcément celle qui PORTE l'entrée, car
// l'historique affiché fusionne celui des lieux organisés.
//
// La bascule lecture ↔ édition réutilise telle quelle celle des cartes de la
// fiche (.card-editable / .card-disp / .card-edit + le trio crayon /
// enregistrer / annuler dans .head-actions, voir le script générique de
// .card-edit-btn dans assets/app.js) : même geste et mêmes boutons que partout
// ailleurs, et aucun JavaScript propre à l'historique. « Annuler » est un lien
// vers la page, comme pour les cartes — c'est la convention de l'application.
$histoEntrees = $histoEntrees ?? [];
$histoModifiable = ($histoModifiable ?? false) && !empty($histoStructureId);
?>
<ul class="hist-list">
    <?php foreach ($histoEntrees as $he): ?>
        <?php
            // Icône choisie sur ce que dit l'entrée, pas seulement sur son type
            // (historique_icone(), lib/booking.php) : un changement de statut
            // porte l'icône de CE statut, une liaison de lieu un maillon, etc.
            [$hIcone, $hClasse, $hLibelle] = historique_icone($he);
            $hi = HISTORIQUE_TYPES[$he['type']] ?? ['Entrée', 'message-square'];
            $editable = $histoModifiable && in_array((string) $he['type'], HISTORIQUE_TYPES_MODIFIABLES, true);
            $editId = 'hist-edit-' . (int) $he['id'];
            $creeLe = (string) $he['cree_le'];
            // L'heure n'est affichée que si elle est connue : les entrées
            // importées sont datées au jour, et « 00:00 » y annoncerait une
            // heure de contact que personne n'a jamais saisie.
            $horodate = $creeLe === '' ? '' : date(strlen($creeLe) > 10 ? 'd.m.Y H:i' : 'd.m.Y', strtotime($creeLe));
            $auteur = trim((string) ($he['u_prenom'] ?? '') . ' ' . (string) ($he['u_nom'] ?? ''));
        ?>
        <li class="hist-item hist-<?= e((string) $he['type']) ?>">
            <span class="hist-ico <?= e($hClasse) ?>" title="<?= e($hLibelle) ?>" aria-hidden="true"><?= icon($hIcone) ?></span>
            <div class="hist-corps<?= $editable ? ' card-editable' : '' ?>">
                <?php if ($editable): ?>
                <div class="head-actions hist-actions">
                    <button type="button" class="btn ghost icon-only btn-sm card-edit-btn" title="Modifier cette entrée" aria-label="Modifier cette entrée"><?= icon('pencil') ?></button>
                    <?php // Bouton hors du <form> (il doit rester visible quand le
                          // formulaire est masqué) : l'attribut form= le rattache,
                          // comme le fait déjà la carte « Historique » juste au-dessus. ?>
                    <button type="submit" form="<?= e($editId) ?>" class="btn icon-only btn-sm card-save-btn" hidden title="Enregistrer" aria-label="Enregistrer"><?= icon('save') ?></button>
                    <a href="?p=structure&id=<?= (int) $histoStructureId ?>" class="btn ghost icon-only btn-sm card-cancel-btn" hidden title="Annuler" aria-label="Annuler"><?= icon('x') ?></a>
                </div>
                <?php endif; ?>
                <?php // Une entrée par ligne : la date qui situe, le contenu qui
                      // occupe la place restante, et la méta rejetée à droite en
                      // gris. La carte est pleine largeur, il n'y a plus de raison
                      // d'empiler ce qui se lit d'un seul balayage — et la colonne
                      // de dates alignées rend le flux scannable. ?>
                <div class="card-disp hist-ligne">
                    <span class="hist-date"><?= e($horodate) ?></span>
                    <span class="hist-texte"><?= (string) $he['contenu'] !== '' ? nl2br(e((string) $he['contenu'])) : '<span class="muted">—</span>' ?></span>
                    <span class="hist-meta">
                        <?= e($hi[0]) ?>
                        <?php if ($auteur !== ''): ?> · <?= e($auteur) ?><?php endif; ?>
                        <?php if (($he['source_label'] ?? '') !== ''): ?> <span class="badge muted-badge"><?= e((string) $he['source_label']) ?></span><?php endif; ?>
                    </span>
                </div>
                <?php if ($editable): ?>
                <form method="post" action="?p=structure_note_modifier" class="card-edit form" id="<?= e($editId) ?>" hidden>
                    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                    <?php // « entree_id » et non « id » : un champ nommé « id » devient
                          // form.id en JavaScript et masque l'attribut id du <form>
                          // lui-même. Rien ne s'en sert aujourd'hui, mais c'est le
                          // genre de piège qui se paie cher plus tard. ?>
                    <input type="hidden" name="entree_id" value="<?= (int) $he['id'] ?>">
                    <input type="hidden" name="structure_id" value="<?= (int) $histoStructureId ?>">
                    <?php // Même rangée et même ordre que le formulaire d'ajout
                          // au-dessus : date, texte, prise de contact. ?>
                    <div class="add-row hist-note-row">
                        <input type="date" name="date" class="hist-note-date" value="<?= e($creeLe !== '' ? date('Y-m-d', strtotime($creeLe)) : '') ?>" aria-label="Date de l'entrée">
                        <textarea name="contenu" rows="1" class="hist-note-texte" aria-label="Contenu" required><?= e((string) $he['contenu']) ?></textarea>
                        <label class="check hist-note-contact"><input type="checkbox" name="est_contact" value="1" <?= $he['type'] === 'mailing' ? 'checked' : '' ?>> Prise de contact</label>
                    </div>
                </form>
                <?php endif; ?>
            </div>
        </li>
    <?php endforeach; ?>
</ul>
