<?php
// Carte « Contacts » d'une fiche structure : les contacts propres à la fiche
// (modifiables sur place), puis ceux des structures liées (lecture seule, un
// bouton mène à leur fiche d'origine), puis le formulaire d'ajout.
//
// Attendu de l'appelant (views/structure_form.php) : $contacts, $contactsLies,
// $lieuxLies, $sid, $peutEcrireBooking.
?>
<?php // Pas de bascule « mode édition » sur cette carte : la commande d'ajout est
      // toujours là, et chaque ligne porte son propre crayon (révélé au survol,
      // permanent au tactile — voir .contact-read .contact-edit-btn, app.css).
      // Un mode global obligeait à deux clics avant toute modification. ?>
<div class="card">
    <div class="card-head-row">
        <h2 class="mt-0">Contacts</h2>
        <?php if ($peutEcrireBooking): ?>
        <button type="button" class="btn ghost btn-sm icon-only" data-show="nouveau-contact-form" data-focus="input[name=prenom]" title="Nouveau contact" aria-label="Nouveau contact"><?= icon('user-plus') ?></button>
        <?php endif; ?>
    </div>
    <?php foreach ($contacts as $c): ?>
        <div class="contact-row">
            <div class="linked-add contact-read <?= $c['actif'] ? '' : 'inactif' ?>">
                <span>
                    <strong><?= e(trim($c['prenom'] . ' ' . $c['nom'])) ?></strong>
                    <?php if ($c['est_administration']): ?><span class="badge">facturation</span><?php endif; ?>
                    <?php if ($c['est_booking']): ?><span class="badge">booking</span><?php endif; ?>
                    <?php if ($c['desinscrit']): ?><span class="badge muted-badge">Désinscrit</span><?php endif; ?>
                    <?php if ($c['role']): ?><span class="muted small"> — <?= e($c['role']) ?></span><?php endif; ?>
                    <?php if ($c['email']): ?><div class="muted small"><?= e($c['email']) ?></div><?php endif; ?>
                    <?php if ($c['telephone']): ?><div class="muted small"><?= e($c['telephone']) ?></div><?php endif; ?>
                    <?php if ($c['formulaire_url']): ?><div class="muted small"> — <a href="<?= e($c['formulaire_url']) ?>" target="_blank" rel="noopener">Formulaire</a></div><?php endif; ?>
                    <?php if ($c['langue']): ?><span class="muted small"><?= e($c['langue']) ?></span><?php endif; ?>
                </span>
                <?php if ($peutEcrireBooking): ?>
                <button type="button" class="btn ghost btn-sm icon-only contact-edit-btn" title="Modifier" aria-label="Modifier"><?= icon('pencil') ?></button>
                <?php endif; ?>
            </div>
            <?php $cfContact = $c; $cfSid = $sid; require __DIR__ . '/_structure_contact_form.php'; ?>
            <?php // Suppression : le formulaire vit ICI, à côté du formulaire d'édition
                  // (jamais dedans, deux <form> ne s'imbriquent pas) ; son bouton est en
                  // bas du cadre d'édition et le vise par form="contact-del-N". ?>
            <form method="post" action="?p=structure_contact_delete" id="contact-del-<?= (int) $c['id'] ?>" data-confirm="Supprimer ce contact ?" hidden>
                <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="structure_id" value="<?= $sid ?>">
                <input type="hidden" name="id" value="<?= (int) $c['id'] ?>">
            </form>
        </div>
    <?php endforeach; ?>
    <?php if (!$contacts && !$contactsLies): ?><p class="muted small">Aucun contact.</p><?php endif; ?>

    <?php
        $sensParStructure = array_column($lieuxLies, 'sens', 'id');
    ?>
    <?php if ($contactsLies): ?>
        <?php foreach ($contactsLies as $c): ?>
            <div class="linked-add contact-read <?= $c['actif'] ? '' : 'inactif' ?>">
                <span>
                    <strong><?= e(trim($c['prenom'] . ' ' . $c['nom'])) ?></strong>
                    <?php if ($c['role']): ?><span class="muted small"> — <?= e($c['role']) ?></span><?php endif; ?>
                    <div class="muted small"><span class="ico-tiny"><?= icon(($sensParStructure[$c['structure_id']] ?? '') === 'organise' ? 'blocks' : 'building') ?></span> <a href="<?= url_avec_retour('?p=structure&id=' . (int) $c['structure_id'], 'structure', $sid) ?>"><?= e((string) $c['structure_nom']) ?></a></div>
                    <?php if ($c['email']): ?><div class="muted small"><?= e($c['email']) ?></div><?php endif; ?>
                    <?php if ($c['telephone']): ?><div class="muted small"><?= e($c['telephone']) ?></div><?php endif; ?>
                </span>
                <?php // Ces contacts appartiennent à une AUTRE structure : on ne les
                      // modifie pas d'ici (ce serait éditer une fiche qu'on n'a pas
                      // sous les yeux). Le bouton y mène plutôt, pour qu'aucune ligne
                      // de la carte ne reste sans commande — une ligne sur deux sans
                      // bouton laissait croire à un bug d'affichage. Icône « bâtiment »
                      // et non crayon : il emmène ailleurs, il ne modifie pas ici. ?>
                <a class="btn ghost btn-sm icon-only contact-lien-btn" href="<?= url_avec_retour('?p=structure&id=' . (int) $c['structure_id'], 'structure', $sid) ?>"
                   title="Modifier chez « <?= e((string) $c['structure_nom']) ?> »" aria-label="Modifier chez <?= e((string) $c['structure_nom']) ?>"><?= icon('building') ?></a>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>

    <?php $cfContact = null; $cfSid = $sid; require __DIR__ . '/_structure_contact_form.php'; ?>

    <script nonce="<?= e(csp_nonce()) ?>">
    (function () {
        // Crayon d'une ligne de contact : la ligne cède la place à son cadre
        // d'édition, la croix (comme « Annuler ») la rend en restaurant les
        // valeurs d'ouverture. Voir lassoInitBlocEdition(), assets/app.js.
        lassoInitBlocEdition({
            bloc: '.contact-row', lecture: '.contact-read', edition: '.contact-edit-form',
            ouvrir: '.contact-edit-btn', annuler: '.contact-cancel-btn',
        });

        // Crayon d'en-tête des AUTRES sections de la fiche (structures liées) :
        // bascule la section en mode édition — seules alors apparaissent ses
        // commandes lier/délier (.edit-only, masquées par défaut, voir app.css).
        // Le crayon devient une croix (annuler) tant que l'édition est ouverte.
        // La carte Contacts, elle, n'a plus de mode global : chaque ligne porte
        // son crayon et le formulaire d'ajout est toujours accessible.
        var editToggleIconPencil = <?= json_encode(icon('pencil')) ?>;
        var editToggleIconX = <?= json_encode(icon('x')) ?>;
        document.querySelectorAll('.edit-toggle-btn').forEach(function (btn) {
            var titreDefaut = btn.title;
            btn.addEventListener('click', function () {
                var sec = btn.closest('.section-editable');
                var on = sec.classList.toggle('editing');
                btn.classList.toggle('on', on);
                btn.innerHTML = on ? editToggleIconX : editToggleIconPencil;
                btn.title = on ? 'Annuler' : titreDefaut;
                btn.setAttribute('aria-label', on ? 'Annuler' : titreDefaut);
            });
        });
    })();
    </script>
</div>
