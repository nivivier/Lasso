<?php
// Fenêtre « Marquer comme contacté » d'une campagne : consigner à la main un
// démarchage qui n'est pas parti d'ici — un appel, une rencontre, un message
// envoyé depuis sa propre boîte. Elle écrit une entrée d'historique de type
// « prise de contact » sur la structure, exactement comme la barre de saisie de
// sa fiche (route_structure_note_ajouter) : même route, même trace.
//
// Les projets sont ceux de la campagne, posés en champs cachés et non à choisir :
// c'est par eux que le contact la fait avancer, et on note ici DANS une campagne.
//
// Attendu de l'appelant : $campagne, $projets (noms), $projetIds, et pour ouvrir
// un élément portant data-noter="<id structure>" data-noter-nom="<nom>".
?>
<div id="noter-modal" class="modal-overlay" hidden>
    <div class="modal-card">
        <form method="post" action="?p=structure_note_ajouter" class="form" id="noter-form">
            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="structure_id" id="noter-structure" value="">
            <input type="hidden" name="est_contact" value="1">
            <input type="hidden" name="retour_campagne" value="<?= (int) $campagne['id'] ?>">
            <?php foreach ($projetIds as $pid): ?>
            <input type="hidden" name="spectacle_ids[]" value="<?= (int) $pid ?>">
            <?php endforeach; ?>
            <div class="cadre-edit-head">
                <span class="cadre-edit-titre" id="noter-titre">Marquer comme contacté</span>
            </div>

            <label><span>Date <?= info_tip("La date du démarchage, pas celle de la saisie : on consigne souvent après coup.") ?></span>
                <input type="date" name="date" id="noter-date" value="<?= e(date('Y-m-d')) ?>">
            </label>
            <label>Ce qui s'est passé
                <textarea name="contenu" id="noter-contenu" rows="5" placeholder="Appel à la programmatrice, rappeler en janvier…" required></textarea>
            </label>
            <p class="muted small">
                <?php // Le projet n'est pas à choisir ici : c'est celui de la campagne,
                      // et c'est lui qui fera avancer sa jauge. ?>
                <?= $projets
                    ? 'Comptera pour ' . e(implode(' · ', $projets)) . ' dans cette campagne.'
                    : "Cette campagne n'a aucun projet : la prise de contact sera consignée, mais n'avancera pas la jauge." ?>
            </p>

            <div class="modal-actions">
                <button type="button" class="btn ghost" id="noter-annuler"><?= icon('x') ?> Annuler</button>
                <button type="submit"><?= icon('check') ?> Enregistrer</button>
            </div>
        </form>
    </div>
</div>
<script nonce="<?= e(csp_nonce()) ?>">
(function () {
    var modal = document.getElementById('noter-modal');
    if (!modal) return;
    var champStructure = document.getElementById('noter-structure');
    var titre = document.getElementById('noter-titre');
    var contenu = document.getElementById('noter-contenu');
    var date = document.getElementById('noter-date');
    var dateDuJour = date.value;

    function fermer() {
        // Ce qu'on a écrit sans enregistrer disparaît : rien n'est allé en base,
        // le retrouver en rouvrant laisserait croire le contraire.
        contenu.value = '';
        date.value = dateDuJour;
        modal.setAttribute('hidden', '');
    }
    document.querySelectorAll('[data-noter]').forEach(function (b) {
        b.addEventListener('click', function () {
            champStructure.value = b.getAttribute('data-noter');
            titre.textContent = 'Marquer comme contacté — ' + b.getAttribute('data-noter-nom');
            contenu.value = '';
            date.value = dateDuJour;
            modal.removeAttribute('hidden');
            contenu.focus({ preventScroll: true });
        });
    });
    document.getElementById('noter-annuler').addEventListener('click', fermer);
    modal.addEventListener('click', function (e) { if (e.target === modal) fermer(); });
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && !modal.hidden) fermer(); });
})();
</script>
